<?php

namespace Atannex\Tagging\Tests\Integration;

use Atannex\Tagging\Exceptions\HierarchyConflict;
use Atannex\Tagging\Exceptions\PolicyRejected;
use Atannex\Tagging\Exceptions\VocabularyCollision;
use Atannex\Tagging\History\ReviewedSourcePolicy;
use Atannex\Tagging\Models\Assignment;
use Atannex\Tagging\Models\Tag;
use Atannex\Tagging\Support\SpaceTransactions;
use Atannex\Tagging\Tests\Support\EngineTestCase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/** Real independent PHP processes; lock waits are observed through a third database connection. */
class ConcurrencyTest extends EngineTestCase
{
    private array $processes = [];

    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (! in_array(DB::getDriverName(), ['mysql', 'pgsql'], true)) {
            $this->markTestSkipped('Real MySQL/PostgreSQL required; SQLite is not a concurrency substitute.');
        }
        config(['database.connections.observer' => config('database.connections.testing')]);
    }

    protected function tearDown(): void
    {
        foreach ($this->processes as $process) {
            if ($process->isRunning()) {
                $process->stop(1);
            }
        }
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        parent::tearDown();
    }

    private function path(): string
    {
        $file = tempnam(sys_get_temp_dir(), 'tagging-race-');
        unlink($file);
        $this->files[] = $file;

        return $file;
    }

    private function launch(array $payload): array
    {
        $payload += ['ready' => $this->path(), 'result' => $this->path()];
        $file = $this->path();
        file_put_contents($file, json_encode($payload, JSON_THROW_ON_ERROR));
        $process = new Process([PHP_BINARY, __DIR__.'/worker.php', $file], dirname(__DIR__, 2), ['TAGGING_DB_RESET' => '0']);
        $process->setTimeout(30);
        $process->start();
        $this->processes[] = $process;
        $this->waitFor(fn () => is_file($payload['ready']), 'Worker failed to boot: '.$process->getErrorOutput());

        return [$process, $payload];
    }

    private function waitFor(callable $condition, string $message): void
    {
        $deadline = microtime(true) + 15;
        while (! $condition()) {
            if (microtime(true) > $deadline) {
                $this->fail($message);
            } usleep(10000);
        }
    }

    private function finish(array $worker): array
    {
        [$process,$payload] = $worker;
        $process->wait();
        $this->assertTrue(is_file($payload['result']), $process->getErrorOutput().$process->getOutput());

        return json_decode(file_get_contents($payload['result']), true, 512, JSON_THROW_ON_ERROR);
    }

    private function race(callable $first, array $payload, bool $rollback = false): array
    {
        $worker = null;
        try {
            app(SpaceTransactions::class)->run($this->space(), function () use ($first, $payload, $rollback, &$worker) {
                $first();
                $worker = $this->launch($payload);
                $pid = json_decode(file_get_contents($worker[1]['ready']), true)['pid'];
                $observer = DB::connection('observer');
                $this->waitFor(function () use ($observer, $pid) {
                    if (DB::getDriverName() === 'pgsql') {
                        return $observer->selectOne("select count(*) as n from pg_stat_activity where pid = ? and wait_event_type = 'Lock'", [$pid])->n > 0;
                    }

                    return $observer->selectOne('select count(*) as n from performance_schema.data_lock_waits w join performance_schema.threads t on t.thread_id = w.requesting_thread_id where t.processlist_id = ?', [$pid])->n > 0;
                }, 'Worker never reached an observed database lock wait.');
                if ($rollback) {
                    throw new \RuntimeException('intentional rollback');
                }
            });
        } catch (\RuntimeException $e) {
            if ($e->getMessage() !== 'intentional rollback') {
                throw $e;
            }
        }

        return $this->finish($worker);
    }

    public function test_simultaneous_creation_in_initially_empty_namespace_recovers_one_identity(): void
    {
        $result = $this->race(fn () => $this->space()->create('Laravel'), ['action' => 'create', 'name' => 'Laravel']);
        $this->assertTrue($result['ok']);
        $this->assertSame(1, Tag::count());
        $this->assertSame(Tag::first()->id, $result['result']['id']);
    }

    public function test_competing_alias_claims_return_typed_collision_without_partial_data(): void
    {
        $s = $this->space();
        $a = $s->create('A');
        $b = $s->create('B');
        $result = $this->race(fn () => $s->alias($a, 'Shared'), ['action' => 'alias', 'name' => 'Shared', 'tag_id' => $b->id]);
        $this->assertFalse($result['ok']);
        $this->assertSame(VocabularyCollision::class, $result['exception']);
        $this->assertSame($a->id, $s->find('Shared')->id);
    }

    public function test_competing_syncs_serialize_complete_sets(): void
    {
        $a = $this->record();
        $s = $this->space();
        $result = $this->race(fn () => $s->sync($a, ['A', 'B']), ['action' => 'sync', 'subject_id' => $a->id, 'tags' => ['C', 'D']]);
        $this->assertTrue($result['ok']);
        $this->assertSame(['C', 'D'], $s->tags($a)->pluck('name')->all());
        $this->assertSame(2, Assignment::count());
    }

    public function test_merge_and_assignment_writes_resolve_the_transferred_namespace(): void
    {
        $s = $this->space();
        $source = $s->create('Source');
        $target = $s->create('Target');
        $a = $this->record();
        $s->attach($a, [$source]);
        $result = $this->race(fn () => $s->merge($source, $target), ['action' => 'attach', 'subject_id' => $a->id, 'tags' => ['Source']]);
        $this->assertTrue($result['ok']);
        $this->assertSame([$target->id], $s->tags($a)->modelKeys());
        $this->assertSame(1, Assignment::count());
    }

    public function test_competing_hierarchy_edits_cannot_commit_a_cycle(): void
    {
        $s = $this->space();
        $a = $s->create('A');
        $b = $s->create('B');
        $result = $this->race(fn () => $s->move($b, $a), ['action' => 'move', 'tag_id' => $a->id, 'parent_id' => $b->id]);
        $this->assertFalse($result['ok']);
        $this->assertSame(HierarchyConflict::class, $result['exception']);
        $this->assertNull($a->fresh()->parent_id);
        $this->assertSame($a->id, $b->fresh()->parent_id);
    }

    public function test_rollback_releases_an_uncommitted_identity_to_the_waiting_writer(): void
    {
        $result = $this->race(fn () => $this->space()->create('Rollback'), ['action' => 'create', 'name' => 'Rollback'], true);
        $this->assertTrue($result['ok']);
        $this->assertSame(1, Tag::count());
        $this->assertSame(1, $result['events']);
    }

    public function test_single_selection_remains_valid_under_competing_writers(): void
    {
        config(['tagging.taxonomies.tags' => ['selection' => 'single']]);
        $s = $this->space();
        $a = $this->record();
        $result = $this->race(fn () => $s->attach($a, ['A']), ['action' => 'attach', 'subject_id' => $a->id, 'tags' => ['B'], 'definition' => ['selection' => 'single']]);
        $this->assertFalse($result['ok']);
        $this->assertSame(PolicyRejected::class, $result['exception']);
        $this->assertSame(['A'], $s->tags($a)->pluck('name')->all());
        $this->assertNull($s->find('B'));
    }

    public function test_review_rejection_wins_against_a_waiting_classifier_write(): void
    {
        config(['tagging.review_tracking' => true, 'tagging.source_policy' => ReviewedSourcePolicy::class, 'tagging.provenance.enabled' => true]);
        $s = $this->space();
        $a = $this->record();
        $tag = $s->create('PHP');
        $result = $this->race(fn () => $s->decide($a, [$tag], 'rejected'), ['action' => 'attach', 'subject_id' => $a->id, 'tags' => ['PHP'], 'review' => true, 'attributes' => ['source' => 'rules:classifier']]);
        $this->assertFalse($result['ok']);
        $this->assertSame(PolicyRejected::class, $result['exception']);
        $this->assertSame(0, Assignment::count());
        $this->assertSame(1, DB::table('tagging_changes')->where('kind', 'review.rejected')->count());
    }

    public function test_eager_loading_discards_a_tag_deleted_by_an_independent_process_between_statements(): void
    {
        $s = $this->space();
        $a = $this->record();
        $s->attach($a, ['Temporary']);
        $tag = $s->find('Temporary');
        $interleaved = false;
        DB::listen(function ($query) use ($tag, &$interleaved) {
            if (! $interleaved && str_starts_with(strtolower($query->sql), 'select') && str_contains($query->sql, 'tagging_assignments')) {
                $interleaved = true;
                $worker = $this->launch(['action' => 'delete', 'tag_id' => $tag->id]);
                $result = $this->finish($worker);
                $this->assertTrue($result['ok'], json_encode($result));
            }
        });
        $loaded = $s->eagerLoad([$a]);
        $this->assertTrue($interleaved);
        $this->assertCount(0, $loaded->tags($a));
        $this->assertCount(0, $loaded->assignments($a));
    }

    private function retryRace(string $action): void
    {
        DB::table('race_counters')->insert(['id' => 1, 'value' => 0]);
        $release = $this->path();
        $one = $this->launch(['action' => $action, 'tenant' => 'one', 'other' => 'two', 'name' => 'One', 'held' => $this->path(), 'release' => $release]);
        $two = $this->launch(['action' => $action, 'tenant' => 'two', 'other' => 'one', 'name' => 'Two', 'held' => $this->path(), 'release' => $release]);
        $this->waitFor(fn () => is_file($one[1]['held']) && is_file($two[1]['held']), 'Both transactions did not reach the barrier.');
        file_put_contents($release, 'release');
        $a = $this->finish($one);
        $b = $this->finish($two);
        $this->assertTrue($a['ok'], json_encode($a));
        $this->assertTrue($b['ok'], json_encode($b));
        $this->assertGreaterThanOrEqual(3, $a['attempts'] + $b['attempts']);
        $this->assertSame(2, DB::table('race_counters')->value('value'));
        $this->assertSame(2, Tag::count());
        $this->assertSame(2, $a['events'] + $b['events']);
        $this->assertSame(2, DB::table('tagging_changes')->where('kind', 'vocabulary.created')->count());
    }

    public function test_real_deadlocks_retry_at_the_outer_boundary_without_duplicate_events_or_history(): void
    {
        $this->retryRace('deadlock');
    }

    public function test_postgresql_serialization_failures_retry_without_reusing_an_aborted_transaction(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL SERIALIZABLE-specific test.');
        }
        $this->retryRace('serialization');
    }

    public function test_postgresql_commit_time_serialization_clears_failed_attempt_notifications(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL commit-time SSI test.');
        }
        DB::table('race_counters')->insert([['id' => 1, 'value' => 0], ['id' => 2, 'value' => 0]]);
        $release = $this->path();
        $commitRelease = $this->path();
        $one = $this->launch(['action' => 'commit_serialization', 'tenant' => 'one', 'name' => 'One', 'row_id' => 1, 'held' => $this->path(), 'release' => $release, 'prepared' => $this->path(), 'commit_release' => $commitRelease]);
        $two = $this->launch(['action' => 'commit_serialization', 'tenant' => 'two', 'name' => 'Two', 'row_id' => 2, 'held' => $this->path(), 'release' => $release, 'prepared' => $this->path(), 'commit_release' => $commitRelease]);
        $this->waitFor(fn () => is_file($one[1]['held']) && is_file($two[1]['held']), 'Both snapshots must be established.');
        file_put_contents($release, 'release');
        $this->waitFor(fn () => is_file($one[1]['prepared']) && is_file($two[1]['prepared']), 'Both transaction bodies must complete before either commit.');
        file_put_contents($commitRelease, 'commit');
        $a = $this->finish($one);
        $b = $this->finish($two);
        $this->assertTrue($a['ok'], json_encode($a));
        $this->assertTrue($b['ok'], json_encode($b));
        $this->assertGreaterThanOrEqual(3, $a['completed'] + $b['completed']);
        $this->assertSame(2, Tag::count());
        $this->assertSame(2, $a['events'] + $b['events']);
        $this->assertSame(2, DB::table('tagging_changes')->where('kind', 'vocabulary.created')->count());
    }
}

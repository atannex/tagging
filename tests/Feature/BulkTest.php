<?php

namespace Atannex\Tagging\Tests\Feature;

use Atannex\Tagging\Events\TagsChanged;
use Atannex\Tagging\Models\Assignment;
use Atannex\Tagging\Models\Tag;
use Atannex\Tagging\Rules\TagSelection;
use Atannex\Tagging\Tagging;
use Atannex\Tagging\TagSpace;
use Atannex\Tagging\Tests\Fixtures\BulkArticle;
use Atannex\Tagging\Tests\Fixtures\BulkUuid;
use Atannex\Tagging\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class BulkTest extends TestCase
{
    protected function defineSubjectTables(): void
    {
        Schema::create('bulk_articles', function (Blueprint $t) {
            $t->id();
            $t->boolean('published')->default(true);
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('bulk_uuids', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->timestamps();
        });
    }

    private function space(string $tenant = '', string $context = 'default'): TagSpace
    {
        return app(Tagging::class)->for($tenant, 'tags', $context);
    }

    private function article(bool $published = true): BulkArticle
    {
        return BulkArticle::create(['published' => $published]);
    }

    public function test_bulk_attach_honors_filters_and_scopes_and_preserves_builder(): void
    {
        $s = $this->space();
        $a = $this->article();
        $b = $this->article();
        $hidden = $this->article(false);
        $deleted = $this->article();
        $deleted->delete();
        $query = BulkArticle::where('published', true)->orderByDesc('id');
        $sql = $query->toSql();
        $report = $s->bulk($query, ['PHP'], attributes: ['source' => 'editor', 'weight' => 0.7]);
        $this->assertSame(2, $report['targeted']);
        $this->assertSame(2, $report['applied']);
        $this->assertSame([(string) $a->id, (string) $b->id], array_column($report['results'], 'key'));
        $this->assertSame($sql, $query->toSql());
        $this->assertSame(0.7, Assignment::first()->weight);
        $this->assertSame('editor', Assignment::first()->source);
        $this->assertCount(0, $s->tags($hidden));
        $this->assertSame(2, Assignment::count());
    }

    public function test_atomic_failure_rolls_back_successes_and_vocabulary_and_events(): void
    {
        $s = $this->space();
        $a = $this->article();
        $b = $this->article();
        $b->attachTags(['Existing']);
        config(['tagging.policies' => ['tags' => ['max_tags' => 1]]]);
        $events = 0;
        Event::listen(TagsChanged::class, function () use (&$events) {
            $events++;
        });
        $report = $s->bulk(BulkArticle::query(), ['New']);
        $this->assertSame(1, $report['succeeded']);
        $this->assertSame(1, $report['failed']);
        $this->assertSame(0, $report['applied']);
        $this->assertTrue($report['rolled_back']);
        $this->assertSame('policy_violation', $report['results'][1]['code']);
        $this->assertSame('max_tags', $report['results'][1]['violations'][0]['code']);
        $this->assertNull($s->find('New'));
        $this->assertSame(1, Assignment::count());
        $this->assertSame(0, $events);
    }

    public function test_non_atomic_mode_commits_successes_and_continues_after_policy_failure(): void
    {
        $s = $this->space();
        $a = $this->article();
        $b = $this->article();
        $c = $this->article();
        $b->attachTags(['Existing']);
        config(['tagging.policies' => ['tags' => ['max_tags' => 1]]]);
        $report = $s->bulk(BulkArticle::query(), ['New'], atomic: false);
        $this->assertSame(2, $report['applied']);
        $this->assertSame(1, $report['failed']);
        $this->assertFalse($report['rolled_back']);
        $this->assertTrue($a->hasTags(['New']));
        $this->assertTrue($b->hasTags(['Existing']));
        $this->assertFalse($b->hasTags(['New']));
        $this->assertTrue($c->hasTags(['New']));
    }

    public function test_bulk_preview_suppresses_model_observers_and_rolls_back_all_results(): void
    {
        $s = $this->space();
        $this->article();
        $this->article();
        $events = 0;
        $models = 0;
        Event::listen(TagsChanged::class, function () use (&$events) {
            $events++;
        });
        Event::listen('eloquent.created: '.Tag::class, function () use (&$models) {
            $models++;
        });
        $report = $s->bulk(BulkArticle::query(), ['New'], dryRun: true, atomic: false);
        $this->assertSame(2, $report['succeeded']);
        $this->assertSame(0, $report['applied']);
        $this->assertTrue($report['rolled_back']);
        $this->assertSame(0, Tag::count());
        $this->assertSame(0, Assignment::count());
        $this->assertSame(0, $events);
        $this->assertSame(0, $models);
    }

    public function test_sync_and_detach_stay_in_selected_space(): void
    {
        $s = $this->space('a');
        $a = $this->article();
        $s->attach($a, ['Old']);
        $this->space('b')->attach($a, ['Other']);
        $this->space('a', 'seo')->attach($a, ['SEO']);
        $this->assertSame(1, $s->bulk(BulkArticle::query(), ['New'], operation: 'sync')['applied']);
        $this->assertSame(['New'], $s->tags($a)->pluck('name')->all());
        $s->bulk(BulkArticle::query(), ['New'], operation: 'detach');
        $this->assertSame(2, Assignment::count());
        $this->assertTrue($this->space('b')->has($a, ['Other']));
    }

    public function test_empty_sync_clears_selected_space_but_empty_detach_is_a_noop(): void
    {
        $a = $this->article();
        $a->attachTags(['PHP']);
        $s = $this->space();
        $s->bulk(BulkArticle::query(), [], operation: 'detach');
        $this->assertSame(1, Assignment::count());
        $s->bulk(BulkArticle::query(), [], operation: 'sync');
        $this->assertSame(0, Assignment::count());
    }

    public function test_snapshot_rechecks_filters_and_skips_subjects_that_stop_matching(): void
    {
        $s = $this->space();
        $a = $this->article();
        $b = $this->article();
        Event::listen('eloquent.created: '.Assignment::class, function () use ($b) {
            $b->update(['published' => false]);
        });
        $report = $s->bulk(BulkArticle::where('published', true), ['PHP']);
        $this->assertSame(2, $report['targeted']);
        $this->assertSame(1, $report['applied']);
        $this->assertSame(1, $report['skipped']);
        $this->assertFalse($b->hasTags(['PHP']));
    }

    public function test_oversized_batches_and_paginated_queries_are_rejected_before_writes(): void
    {
        $this->article();
        $this->article();
        config(['tagging.max_bulk_subjects' => 1]);
        try {
            $this->space()->bulk(BulkArticle::query(), ['PHP']);
            $this->fail('Expected cap');
        } catch (\LengthException) {
        }
        $this->assertSame(0, Tag::count());
        $this->expectException(\InvalidArgumentException::class);
        $this->space()->bulk(BulkArticle::limit(1), ['PHP']);
    }

    public function test_bulk_supports_uuid_keys_and_empty_candidate_sets(): void
    {
        $a = BulkUuid::create(['id' => '019a1234-0000-7000-8000-123456789abc']);
        $report = $this->space()->bulk(BulkUuid::query(), (function () {
            yield 'PHP';
        })());
        $this->assertSame($a->id, $report['results'][0]['key']);
        $this->assertTrue($a->hasTags(['PHP']));
        $empty = $this->space()->bulk(BulkArticle::query(), ['NeverCreated']);
        $this->assertSame(0, $empty['targeted']);
        $this->assertNull($this->space()->find('NeverCreated'));
    }

    public function test_invalid_assignment_data_is_reported_and_outer_transaction_survives(): void
    {
        $this->article();
        DB::transaction(function () {
            $report = $this->space()->bulk(BulkArticle::query(), ['PHP'], attributes: ['weight' => -1]);
            $this->assertSame('invalid_assignment', $report['results'][0]['code']);
            $this->assertSame(0, $report['applied']);
            $this->article();
        });
        $this->assertSame(2, BulkArticle::count());
        $this->assertSame(0, Tag::count());
    }

    public function test_after_commit_listener_failures_propagate_instead_of_claiming_mutations_failed(): void
    {
        $this->article();
        $this->article();
        Event::listen(TagsChanged::class, function () {
            throw new \InvalidArgumentException('Downstream event listener failed');
        });
        try {
            $this->space()->bulk(BulkArticle::query(), ['PHP'], atomic: false);
            $this->fail('Expected listener failure to propagate');
        } catch (\InvalidArgumentException $exception) {
            $this->assertSame('Downstream event listener failed', $exception->getMessage());
        }
        $this->assertSame(1, Assignment::count()); // First subject committed; second was not attempted.
    }

    public function test_tag_selection_resolves_active_names_and_aliases_without_writes(): void
    {
        $s = $this->space('a');
        $tag = $s->create('PHP');
        $s->alias($tag, 'Language');
        $valid = Validator::make(['tags' => ['Language']], ['tags' => [new TagSelection($s, min: 1, max: 2)]]);
        $this->assertTrue($valid->passes());
        $this->assertSame(1, Tag::count());
        $this->assertSame(0, Assignment::count());
        $this->assertFalse(Validator::make(['tags' => ['PHP', 'Language']], ['tags' => [new TagSelection($s)]])->passes());
        $this->assertTrue(Validator::make(['tags' => ['PHP', 'Language']], ['tags' => [new TagSelection($s, distinct: false)]])->passes());
    }

    public function test_tag_selection_rejects_wrong_shape_types_unknown_and_inactive_names(): void
    {
        $s = $this->space();
        $s->create('Disabled', ['active' => false]);
        foreach ([['x' => 'Disabled'], [12], ['unknown'], ['Disabled'], [' '], ['nested' => ['PHP']]] as $value) {
            $validator = Validator::make(['tags' => $value], ['tags' => [new TagSelection($s)]]);
            $this->assertFalse($validator->passes());
        }
        $this->assertSame(1, Tag::count());
    }

    public function test_tag_selection_bounds_and_tenant_isolation(): void
    {
        $s = $this->space('a');
        $s->create('PHP');
        $this->space('b')->create('Foreign');
        $this->assertFalse(Validator::make(['tags' => []], ['tags' => ['present', new TagSelection($s, min: 1)]])->passes());
        $this->assertFalse(Validator::make(['tags' => ['PHP', 'PHP']], ['tags' => [new TagSelection($s, max: 1)]])->passes());
        $this->assertFalse(Validator::make(['tags' => ['Foreign']], ['tags' => [new TagSelection($s)]])->passes());
        $this->expectException(\InvalidArgumentException::class);
        new TagSelection($s, min: 2, max: 1);
    }

    public function test_tag_selection_messages_are_translatable_and_include_field_names(): void
    {
        $s = $this->space();
        $english = Validator::make(['topics' => ['unknown']], ['topics' => [new TagSelection($s)]]);
        $this->assertSame('The topics field contains an unknown or inactive tag.', $english->errors()->first('topics'));
        Lang::addLines(['validation.unavailable' => 'Le champ :attribute contient une étiquette indisponible.'], 'fr', 'tagging');
        app()->setLocale('fr');
        $french = Validator::make(['topics' => ['unknown']], ['topics' => [new TagSelection($s)]]);
        $this->assertSame('Le champ topics contient une étiquette indisponible.', $french->errors()->first('topics'));
    }
}

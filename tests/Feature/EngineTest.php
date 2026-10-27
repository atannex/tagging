<?php

namespace Atannex\Tagging\Tests\Feature;

use Atannex\Tagging\Contracts\AuditSink;
use Atannex\Tagging\Events\AssignmentsChanged;
use Atannex\Tagging\Events\TagsChanged;
use Atannex\Tagging\Events\VocabularyChanged;
use Atannex\Tagging\Exceptions\HierarchyConflict;
use Atannex\Tagging\Exceptions\ImportFailure;
use Atannex\Tagging\Exceptions\InvalidConfiguration;
use Atannex\Tagging\Exceptions\PolicyRejected;
use Atannex\Tagging\Exceptions\ScopeViolation;
use Atannex\Tagging\Exceptions\VocabularyCollision;
use Atannex\Tagging\History\ReviewedSourcePolicy;
use Atannex\Tagging\Jobs\TagSubject;
use Atannex\Tagging\Models\Assignment;
use Atannex\Tagging\Models\Tag;
use Atannex\Tagging\Query\AssignmentFilter;
use Atannex\Tagging\Support\SpaceTransactions;
use Atannex\Tagging\Tagging;
use Atannex\Tagging\TagSpace;
use Atannex\Tagging\Tests\Fixtures\CollectAudit;
use Atannex\Tagging\Tests\Fixtures\ColorMetadata;
use Atannex\Tagging\Tests\Fixtures\OwnerAccess;
use Atannex\Tagging\Tests\Fixtures\Record;
use Atannex\Tagging\Tests\Support\EngineTestCase;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;

class EngineTest extends EngineTestCase
{
    public function test_strict_selection_is_opt_in_and_revalidated_after_serialization(): void
    {
        $implicit = app(Tagging::class)->for();
        $a = $this->record();
        $a->attachTags(['Legacy']);
        config(['tagging.strict_tenants' => true]);
        try {
            $implicit->tags($a);
            $this->fail('Expected strict rejection');
        } catch (ScopeViolation) {
        }
        $explicit = unserialize(serialize($this->space('')));
        $this->assertSame(['Legacy'], $explicit->tags($a)->pluck('name')->all());
        $this->expectException(ScopeViolation::class);
        $a->attachTags(['Implicit']);
    }

    public function test_subject_access_covers_reads_writes_scopes_facets_bulk_and_eager_loading(): void
    {
        $a = $this->record('a');
        $b = $this->record('b');
        $s = $this->space('a');
        $s->attach($a, ['PHP']);
        $s->attach($b, ['PHP']);
        config(['tagging.subject_access' => OwnerAccess::class]);
        $this->assertSame([$a->id], Record::withAnyTags(['PHP'], $s)->pluck('id')->all());
        $this->assertSame(1, $s->facets(Record::query())->first()->facet_count);
        $this->assertSame(1, $s->bulk(Record::query(), ['New'])['targeted']);
        foreach ([fn () => $s->tags($b), fn () => $s->attach($b, ['X']), fn () => $s->eagerLoad([$a, $b])] as $call) {
            try {
                $call();
                $this->fail('Expected ownership rejection');
            } catch (ScopeViolation) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_taxonomy_controls_creation_approval_and_single_selection(): void
    {
        $s = $this->space();
        $a = $this->record();
        config(['tagging.taxonomies.tags' => ['auto_create' => false, 'approved_only' => true, 'selection' => 'single']]);
        try {
            $s->attach($a, ['New']);
            $this->fail('Expected no creation');
        } catch (PolicyRejected) {
        }
        $x = $s->create('X');
        $y = $s->create('Y');
        try {
            $s->attach($a, [$x]);
            $this->fail('Expected approval');
        } catch (PolicyRejected) {
        }
        $s->approve($x);
        $s->approve($y);
        $s->attach($a, [$x]);
        try {
            $s->attach($a, [$y]);
            $this->fail('Expected single selection');
        } catch (PolicyRejected) {
        }
        $this->assertTrue($s->has($a, ['X']));
        $s->sync($a, [$y]);
        $this->assertTrue($s->has($a, ['Y']));
    }

    public function test_definition_tenant_and_context_overrides_and_current_limits(): void
    {
        config(['tagging.taxonomies.tags' => ['max_assignments' => 1, 'tenants' => ['a' => ['max_assignments' => 2, 'contexts' => ['seo' => ['max_assignments' => 3]]]]]]);
        $a = $this->record();
        $this->space('a')->attach($a, ['A', 'B']);
        $this->space('a', 'tags', 'seo')->attach($a, ['A', 'B', 'C']);
        $s = $this->space('b');
        $s->attach($a, ['Old'], ['expires_at' => now()->subDay()]);
        $s->attach($a, ['Current']);
        $this->assertSame(1, $s->tags($a)->count());
    }

    public function test_depth_and_parent_assignment_rules_rollback_moves(): void
    {
        $s = $this->space();
        $root = $s->create('Root');
        $child = $s->create('Child');
        $leaf = $s->create('Leaf');
        config(['tagging.taxonomies.tags' => ['max_depth' => 2]]);
        $s->move($child, $root);
        try {
            $s->move($leaf, $child);
            $this->fail('Expected depth rejection');
        } catch (HierarchyConflict) {
        }
        $this->assertNull($leaf->fresh()->parent_id);
        $a = $this->record();
        $s->attach($a, [$leaf]);
        config(['tagging.taxonomies.tags' => ['assign_parents' => false]]);
        try {
            $s->move($root, $leaf);
            $this->fail('Expected parent assignment rejection');
        } catch (PolicyRejected) {
        }
        $this->assertNull($root->fresh()->parent_id);
    }

    public function test_tag_and_assignment_metadata_validators_are_separate(): void
    {
        $s = $this->space();
        $a = $this->record();
        config(['tagging.taxonomies.tags' => ['tag_metadata_validator' => ColorMetadata::class]]);
        try {
            $s->create('Bad');
            $this->fail('Expected metadata rejection');
        } catch (PolicyRejected) {
        }
        $tag = $s->create('Good', ['metadata' => ['color' => 'red']]);
        $s->attach($a, [$tag]);
        config(['tagging.taxonomies.tags' => ['assignment_metadata_validator' => ColorMetadata::class]]);
        try {
            $s->attach($a, [$tag], ['metadata' => []]);
            $this->fail('Expected assignment metadata rejection');
        } catch (PolicyRejected) {
        }
        $this->assertNull(Assignment::first()->metadata);
    }

    public function test_reviewed_sources_rejections_and_transactional_history_are_idempotent(): void
    {
        config(['tagging.review_tracking' => true, 'tagging.source_policy' => ReviewedSourcePolicy::class, 'tagging.provenance.enabled' => true]);
        $s = $this->space()->withOperation('editor-1', 'request-1');
        $a = $this->record();
        $tag = $s->create('PHP');
        $s->attach($a, [$tag]);
        $s->decide($a, [$tag], 'approved');
        try {
            $s->attach($a, [$tag], ['source' => 'rules:model', 'weight' => 0.7]);
            $this->fail('Expected reviewed protection');
        } catch (PolicyRejected) {
        }
        $this->assertSame('manual', Assignment::first()->source);
        $count = DB::table('tagging_changes')->count();
        $s->attach($a, [$tag]);
        $s->decide($a, [$tag], 'approved');
        $this->assertSame($count, DB::table('tagging_changes')->count());
        $s->decide($a, [$tag], 'rejected');
        $this->assertSame(0, Assignment::count());
        try {
            $s->attach($a, [$tag], ['source' => 'rules:model']);
            $this->fail('Expected explicit rejection');
        } catch (PolicyRejected) {
        }
        $this->assertSame('editor-1', DB::table('tagging_changes')->where('kind', 'review.rejected')->value('actor_id'));
        $this->assertSame('request-1', DB::table('tagging_changes')->where('kind', 'review.rejected')->value('operation_id'));
    }

    public function test_legacy_overwrite_and_legacy_noops_are_preserved_while_deltas_are_precise(): void
    {
        $s = $this->space();
        $a = $this->record();
        $tag = $s->create('PHP');
        $deltas = [];
        $legacy = 0;
        Event::listen(AssignmentsChanged::class, function ($e) use (&$deltas) {
            $deltas[] = $e;
        });
        Event::listen(TagsChanged::class, function () use (&$legacy) {
            $legacy++;
        });
        $s->attach($a, [$tag]);
        $s->attach($a, [$tag]);
        $s->attach($a, [$tag], ['source' => 'rules:model', 'weight' => 2.5]);
        $this->assertSame(3, $legacy);
        $this->assertCount(2, $deltas);
        $this->assertCount(1, $deltas[0]->attached);
        $this->assertSame(1.0, $deltas[1]->updated[0]['before']['weight']);
        $this->assertSame(2.5, $deltas[1]->updated[0]['after']['weight']);
    }

    public function test_provenance_and_audit_notifications_do_not_survive_rollback(): void
    {
        config(['tagging.provenance.enabled' => true]);
        $sink = new CollectAudit;
        $this->app->instance(AuditSink::class, $sink);
        $s = $this->space();
        $a = $this->record();
        try {
            DB::transaction(function () use ($s, $a) {
                $s->attach($a, ['Rolled']);
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {
        }
        $this->assertSame(0, DB::table('tagging_changes')->count());
        $this->assertCount(0, $sink->events);
        $s->attach($a, ['Kept']);
        $this->assertGreaterThan(0, DB::table('tagging_changes')->count());
        $this->assertNotEmpty($sink->events);
    }

    public function test_lifecycle_deletion_policies_keep_hierarchy_and_history_consistent(): void
    {
        config(['tagging.provenance.enabled' => true]);
        $s = $this->space();
        $a = $this->record();
        $root = $s->create('Root');
        $child = $s->create('Child');
        $s->move($child, $root);
        $s->attach($a, [$child]);
        try {
            $s->delete($root, 'restrict');
            $this->fail('Expected children restriction');
        } catch (HierarchyConflict) {
        }
        try {
            $s->delete($child, assignments: 'restrict');
            $this->fail('Expected assignment restriction');
        } catch (PolicyRejected) {
        }
        $s->delete($root, 'promote');
        $this->assertNull($child->fresh()->parent_id);
        $s->delete($child);
        $this->assertSame(0, Assignment::count());
        $this->assertSame(0, Tag::count());
        $this->assertGreaterThan(0, DB::table('tagging_changes')->where('kind', 'vocabulary.deleted')->count());
    }

    public function test_eager_loading_has_two_queries_for_many_models_and_no_scope_leakage(): void
    {
        $s = $this->space('a');
        $models = [];
        for ($i = 0; $i < 15; $i++) {
            $a = $this->record();
            $s->attach($a, ['PHP']);
            $this->space('b')->attach($a, ['Other']);
            $models[] = $a;
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        $loaded = $s->eagerLoad($models);
        $count = count(DB::getQueryLog());
        foreach ($models as $a) {
            $this->assertSame(['PHP'], $loaded->tags($a)->pluck('name')->all());
            $this->assertCount(1, $loaded->assignments($a));
        }
        $this->assertSame(2, $count);
        $this->assertSame($count, count(DB::getQueryLog()));
        DB::disableQueryLog();
    }

    public function test_advanced_filters_combine_on_one_row_and_preserve_subject_uniqueness(): void
    {
        $s = $this->space();
        $root = $s->create('Root');
        $leaf = $s->create('Leaf');
        $s->move($leaf, $root);
        $a = $this->record();
        $s->attach($a, [$root], ['source' => 'editor', 'weight' => 0.8]);
        $this->assertSame(1, Record::withTagMatch(['Leaf'], ancestors: true, minWeight: 0.5, maxWeight: 1, source: 'editor')->count());
        $this->assertSame(0, Record::withTagMatch(['Root'], descendants: true, includeRoot: false)->count());
        $this->assertSame(0, Record::withTagMatch(['Leaf'], ancestors: true, maxWeight: 0.5)->count());
        $s->attach($a, [$leaf], ['expires_at' => now()->subMinute(), 'weight' => 2]);
        $this->assertSame(1, Record::withTagMatch(['Leaf'], expiration: 'expired')->count());
        $this->assertSame(2.0, $s->currentAssignments($a, new AssignmentFilter(expiration: 'all', orderBy: 'weight', direction: 'desc'))->first()->weight);
    }

    public function test_v2_transfer_approval_and_conflict_policies_and_metadata_rules(): void
    {
        $s = $this->space('a');
        $tag = $s->create('PHP');
        $s->approve($tag);
        $document = $s->export(2);
        $target = $this->space('b');
        $target->import($document);
        $this->assertNotNull($target->find('PHP')->approved_at);
        $this->assertSame($document, $target->export(2));
        $document['tags'][0]['metadata'] = ['changed' => true];
        $target->import($document, conflict: 'keep');
        $this->assertSame([], $target->find('PHP')->metadata);
        try {
            $target->import($document, conflict: 'fail');
            $this->fail('Expected deterministic collision');
        } catch (ImportFailure) {
        }
        config(['tagging.taxonomies.tags' => ['tag_metadata_validator' => ColorMetadata::class]]);
        $this->expectException(ImportFailure::class);
        $target->import($document);
    }

    public function test_scoped_pruning_and_retention_are_bounded_and_preserve_decisions(): void
    {
        config(['tagging.provenance.enabled' => true, 'tagging.review_tracking' => true, 'tagging.maintenance_batch_size' => 1]);
        $a = $this->record();
        $s = $this->space('a');
        $tag = $s->create('PHP');
        $s->attach($a, [$tag], ['expires_at' => now()->subDay()]);
        $s->decide($a, [$tag], 'approved');
        $this->space('b')->attach($a, ['Other'], ['expires_at' => now()->subDay()]);
        $this->artisan('tagging:prune', ['--tenant' => 'a'])->assertSuccessful();
        $this->assertSame(1, Assignment::count());
        DB::table('tagging_changes')->update(['created_at' => now()->subYears(2)]);
        $this->artisan('tagging:history-prune', ['--tenant' => 'a', '--days' => '365'])->assertSuccessful();
        $this->assertSame(0, DB::table('tagging_changes')->where('tenant', 'a')->count());
        $this->assertSame(1, DB::table('tagging_decisions')->count());
    }

    public function test_upgrade_migration_preserves_original_identities_and_assignments(): void
    {
        $s = $this->space();
        $a = $this->record();
        $s->attach($a, ['Before Upgrade']);
        $id = $s->find('Before Upgrade')->id;
        $assignmentId = Assignment::first()->id;
        $migration = require __DIR__.'/../../database/migrations/2026_10_28_000001_harden_tagging_engine.php';
        $binary = require __DIR__.'/../../database/migrations/2026_10_28_000002_enforce_binary_scope_keys.php';
        $parents = require __DIR__.'/../../database/migrations/2026_10_28_000003_index_tagging_parents.php';
        $parents->down();
        $binary->down();
        $migration->down();
        $this->assertFalse(Schema::hasColumn('tagging_tags', 'approved_at'));
        $this->assertSame(1, DB::table('tagging_assignments')->count());
        $migration->up();
        $binary->up();
        $parents->up();
        $this->assertSame($id, $s->find('Before Upgrade')->id);
        $this->assertSame($assignmentId, Assignment::first()->id);
        $this->assertNull($s->find('Before Upgrade')->approved_at);
        $this->assertTrue($s->health()['healthy']);
    }

    public function test_queued_work_rechecks_current_tenant_ownership_and_definitions(): void
    {
        $a = $this->record('a');
        $job = unserialize(serialize(new TagSubject('a', Record::class, (string) $a->id, ['PHP'])));
        config(['tagging.strict_tenants' => true, 'tagging.subject_access' => OwnerAccess::class]);
        $job->handle(app(Tagging::class));
        $this->assertTrue($this->space('a')->has($a, ['PHP']));
        $a->update(['tenant' => 'b']);
        try {
            $job->handle(app(Tagging::class));
            $this->fail('Expected a fresh ownership check');
        } catch (ModelNotFoundException) {
        }
        $this->assertSame(1, Assignment::count());
    }

    public function test_merge_revalidates_final_taxonomy_state_and_preserves_target_decisions(): void
    {
        config(['tagging.review_tracking' => true]);
        $s = $this->space();
        $a = $this->record();
        $source = $s->create('Source');
        $target = $s->create('Target');
        $s->attach($a, [$source]);
        $s->decide($a, [$source], 'approved');
        $s->decide($a, [$target], 'rejected');
        config(['tagging.source_policy' => ReviewedSourcePolicy::class]);
        $s->merge($source, $target);
        $this->assertSame($target->id, $s->find('Source')->id);
        $this->assertSame('rejected', DB::table('tagging_decisions')->where('tag_id', $target->id)->value('decision'));
        $this->assertSame(1, DB::table('tagging_decisions')->count());
    }

    public function test_vocabulary_lifecycle_event_coverage_and_noop_deltas(): void
    {
        $actions = [];
        Event::listen(VocabularyChanged::class, function ($event) use (&$actions) {
            $actions[] = $event->action;
        });
        $s = $this->space();
        $root = $s->create('Root');
        $tag = $s->create('Old');
        $s->alias($tag, 'Alias');
        $s->removeAlias($tag, 'Alias');
        $s->rename($tag, 'New');
        $s->move($tag, $root);
        $s->deactivate($tag);
        $s->activate($tag);
        $s->delete($tag);
        foreach (['created', 'alias_added', 'alias_removed', 'renamed', 'moved', 'deactivated', 'activated', 'deleted'] as $action) {
            $this->assertContains($action, $actions);
        }
    }

    public function test_raw_relationship_counts_and_eager_loading_preserve_all_spaces(): void
    {
        $a = $this->record();
        $this->space('a')->attach($a, ['One']);
        $this->space('b')->attach($a, ['Two'], ['expires_at' => now()->subDay()]);
        $model = Record::withCount('tagAssignments')->with('tagAssignments')->findOrFail($a->id);
        $this->assertSame(2, $model->tag_assignments_count);
        $this->assertCount(2, $model->tagAssignments);
    }

    public function test_owner_scoping_applies_to_aggregate_apis_without_candidate_builders(): void
    {
        $s = $this->space('a');
        $owned = $this->record('a');
        $other = $this->record('b');
        $s->attach($owned, ['Seed', 'Owned']);
        $s->attach($other, ['Seed', 'Foreign']);
        config(['tagging.subject_access' => OwnerAccess::class]);
        $this->assertSame(['Owned'], $s->related($s->find('Seed'))->pluck('name')->all());
        $this->assertSame(1, $s->popular()->first()->usage_count);
        $usage = $s->usage(now()->subDay(), now()->addDay())->keyBy('name');
        $this->assertSame(0, $usage['Foreign']->usage_count);
    }

    public function test_scope_keys_remain_case_sensitive_on_every_database(): void
    {
        $a = $this->record();
        $upper = $this->space('Tenant');
        $lower = $this->space('tenant');
        $upper->attach($a, ['PHP']);
        $lower->attach($a, ['PHP']);
        $this->assertNotSame($upper->find('PHP')->id, $lower->find('PHP')->id);
        $this->space('Tenant', 'tags', 'Editorial')->attach($a, ['PHP']);
        $this->space('Tenant', 'tags', 'editorial')->attach($a, ['PHP']);
        $this->space('tenant ')->attach($a, ['PHP']);
        $this->assertSame(5, Assignment::count());
        $this->assertSame(1, $upper->assignments($a)->count());
        if (DB::getDriverName() === 'mysql') {
            $migration = require __DIR__.'/../../database/migrations/2026_10_28_000002_enforce_binary_scope_keys.php';
            try {
                $migration->down();
                $this->fail('Downgrade must not collapse distinct scope identities');
            } catch (VocabularyCollision) {
                $this->addToAssertionCount(1);
            }
        }
        // This fixture intentionally uses identities that v1 could not represent on MySQL.
        // Remove the fixture data before Testbench rolls migrations back, rather than weakening the invariant.
        DB::table('tagging_assignments')->delete();
        DB::table('tagging_names')->delete();
        DB::table('tagging_tags')->delete();

    }

    public function test_generator_materialization_is_bounded_before_any_write(): void
    {
        config(['tagging.max_tags_per_operation' => 2]);
        $consumed = 0;
        $a = $this->record();
        $input = (function () use (&$consumed) {
            while (true) {
                $consumed++;
                yield 'Tag '.$consumed;
            }
        })();
        try {
            $this->space()->attach($a, $input);
            $this->fail('Expected bounded input rejection');
        } catch (PolicyRejected) {
        }
        $this->assertSame(3, $consumed);
        $this->assertSame(0, Tag::count());
    }

    public function test_merge_preserves_inactive_targets_and_legacy_event_coverage(): void
    {
        $s = $this->space();
        $a = $this->record();
        $source = $s->create('Source');
        $target = $s->create('Target', ['active' => false]);
        $s->attach($a, [$source]);
        $legacy = 0;
        Event::listen(TagsChanged::class, function () use (&$legacy) {
            $legacy++;
        });
        $s->merge($source, $target);
        $this->assertSame(0, $legacy);
        $this->assertSame(1, Assignment::count());
        $this->assertCount(0, $s->tags($a));
    }

    public function test_eager_loading_batches_current_ownership_checks_without_n_plus_one_queries(): void
    {
        $s = $this->space('a');
        $models = [];
        for ($i = 0; $i < 15; $i++) {
            $a = $this->record('a');
            $s->attach($a, ['PHP']);
            $models[] = $a;
        }
        config(['tagging.subject_access' => OwnerAccess::class]);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $loaded = $s->eagerLoad($models);
        $this->assertSame(3, count(DB::getQueryLog()));
        foreach ($models as $a) {
            $this->assertCount(1, $loaded->tags($a));
        }
        $this->assertSame(3, count(DB::getQueryLog()));
        DB::disableQueryLog();
        Record::whereKey($models[0]->id)->update(['tenant' => 'b']);
        try {
            $s->tags($models[0]);
            $this->fail('Expected current ownership rejection');
        } catch (ScopeViolation) {
        }
        $this->expectException(ScopeViolation::class);
        $s->eagerLoad($models);
    }

    public function test_vocabulary_wide_rules_cannot_disagree_between_assignment_contexts(): void
    {
        config(['tagging.taxonomies.tags' => ['contexts' => ['seo' => ['max_depth' => 2]]]]);
        $this->expectException(InvalidConfiguration::class);
        $this->space('', 'tags', 'seo')->create('Invalid configuration');
    }

    public function test_invalid_boolean_settings_are_rejected_before_mutation(): void
    {
        config(['tagging.provenance.enabled' => 'yes']);
        try {
            $this->space()->create('Invalid');
            $this->fail('Expected invalid configuration');
        } catch (InvalidConfiguration) {
        }
        $this->assertSame(0, Tag::count());
    }

    public function test_current_queries_recheck_expiration_at_the_exact_boundary(): void
    {
        $this->travelTo(now()->startOfSecond());
        $s = $this->space();
        $a = $this->record();
        $s->attach($a, ['Temporary'], ['expires_at' => now()->addMinute()]);
        $this->assertCount(1, $s->tags($a));
        $this->travel(1)->minutes();
        $this->assertCount(0, $s->tags($a));
        $this->assertCount(0, $s->eagerLoad([$a])->tags($a));
        $this->assertSame(1, Record::withTagMatch(['Temporary'], expiration: 'expired')->count());
        $this->assertSame(1, Assignment::count());
        $this->travelBack();
    }

    public function test_named_arguments_cannot_bypass_explicit_tenant_selection(): void
    {
        config(['tagging.strict_tenants' => true]);
        foreach ([fn () => app(Tagging::class)->for(taxonomy: 'topics'), fn () => new TagSpace(context: 'editorial')] as $call) {
            try {
                $call();
                $this->fail('Expected explicit tenant selection');
            } catch (ScopeViolation) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame('', app(Tagging::class)->for('')->tenant);
    }

    public function test_v1_serialized_space_payloads_restore_without_uninitialized_fields(): void
    {
        $legacy = serialize((object) ['tenant' => 'a', 'taxonomy' => 'topics', 'context' => 'editorial']);
        $legacy = str_replace('O:8:"stdClass"', 'O:'.strlen(TagSpace::class).':"'.TagSpace::class.'"', $legacy);
        $restored = unserialize($legacy);
        $this->assertInstanceOf(TagSpace::class, $restored);
        $this->assertNull($restored->actorId);
        $this->assertNull($restored->operationId);
        $this->assertSame('a', $restored->tenant);
        config(['tagging.strict_tenants' => true]);
        $this->assertSame('a', unserialize($legacy)->tenant);
    }

    public function test_domain_messages_are_not_mistaken_for_database_concurrency_errors(): void
    {
        $attempts = 0;
        try {
            app(SpaceTransactions::class)->run($this->space(), function () use (&$attempts) {
                $attempts++;
                throw new PolicyRejected('Deadlock found when trying to get lock');
            });
            $this->fail('Expected rejection');
        } catch (PolicyRejected) {
        }
        $this->assertSame(1, $attempts);
    }

    public function test_unrelated_sql_errors_roll_back_to_a_savepoint_and_leave_outer_transaction_usable(): void
    {
        DB::transaction(function () {
            try {
                app(SpaceTransactions::class)->run($this->space(), fn () => DB::select('select missing_column from tagging_tags'));
                $this->fail('Expected SQL error');
            } catch (QueryException) {
            }
            $this->space()->create('After error');
        });
        $this->assertNotNull($this->space()->find('After error'));
    }

    public function test_a_post_commit_serialization_error_from_a_listener_does_not_replay_the_mutation(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL notification error classification.');
        }
        $calls = 0;
        Event::listen(VocabularyChanged::class, function () use (&$calls) {
            $calls++;
            DB::statement(<<<'SQL'
DO $$ BEGIN RAISE EXCEPTION 'audit delivery unavailable' USING ERRCODE = '40001'; END $$
SQL);
        });
        try {
            $this->space()->create('Committed');
            $this->fail('Expected delivery error');
        } catch (QueryException) {
        }
        $this->assertSame(1, $calls);
        $this->assertSame(1, Tag::count());
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_operational_errors_do_not_print_metadata_from_sql_bindings(): void
    {
        config(['tagging.provenance.enabled' => true]);
        $file = tempnam(sys_get_temp_dir(), 'tagging-private-');
        $document = ['version' => 1, 'tags' => [['name' => 'Private', 'slug' => 'private', 'parent' => null, 'aliases' => [], 'metadata' => ['token' => 'PRIVATE_SECRET'], 'translations' => [], 'active' => true]]];
        file_put_contents($file, json_encode($document, JSON_THROW_ON_ERROR));
        Schema::rename('tagging_changes', 'tagging_changes_hidden');
        try {
            $this->artisan('tagging:import', ['file' => $file, '--tenant' => 'a'])->expectsOutputToContain('Database operation failed')->doesntExpectOutputToContain('PRIVATE_SECRET')->assertFailed();
        } finally {
            Schema::rename('tagging_changes_hidden', 'tagging_changes');
            unlink($file);
        }
        $this->assertSame(0, Tag::count());
    }

    public function test_diagnostics_detect_missing_indexes_and_unresolved_morphs_without_repairs(): void
    {
        $s = $this->space();
        $a = $this->record();
        $s->attach($a, ['PHP']);
        DB::table('tagging_assignments')->update(['taggable_type' => 'unresolved-map']);
        $this->assertContains('unresolved_morph', array_column($s->health()['issues'], 'code'));
        Schema::table('tagging_assignments', fn ($table) => $table->dropIndex('tagging_context_current'));
        $this->assertContains('missing_index', array_column($s->health()['issues'], 'code'));
        Schema::table('tagging_assignments', fn ($table) => $table->index(['context', 'tag_id', 'expires_at'], 'tagging_context_current'));
    }
}

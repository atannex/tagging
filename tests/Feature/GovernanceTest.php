<?php

namespace Atannex\Tagging\Tests\Feature;

use Atannex\Tagging\Automation\Rule;
use Atannex\Tagging\Events\TagsChanged;
use Atannex\Tagging\Governance\PolicyViolation;
use Atannex\Tagging\Models\Assignment;
use Atannex\Tagging\Models\Tag;
use Atannex\Tagging\Tagging;
use Atannex\Tagging\TagSpace;
use Atannex\Tagging\Tests\Fixtures\GovernedRecord;
use Atannex\Tagging\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;

class GovernanceTest extends TestCase
{
    protected function defineSubjectTables(): void
    {
        Schema::create('governed_records', function (Blueprint $t) {
            $t->id();
            $t->timestamps();
        });
    }

    private function space(string $tenant = '', string $taxonomy = 'tags', string $context = 'default'): TagSpace
    {
        return app(Tagging::class)->for($tenant, $taxonomy, $context);
    }

    private function record(): GovernedRecord
    {
        return GovernedRecord::create();
    }

    private function policy(array $options, string $taxonomy = 'tags'): void
    {
        config(['tagging.policies' => [$taxonomy => $options]]);
    }

    public function test_cardinality_rejects_attach_and_detach_without_losing_data(): void
    {
        $s = $this->space();
        $a = $this->record();
        $this->policy(['min_tags' => 1, 'max_tags' => 1]);
        $s->attach($a, ['Draft']);
        try {
            $s->attach($a, ['Published']);
            $this->fail('Expected maximum rejection');
        } catch (PolicyViolation $e) {
            $this->assertSame('max_tags', $e->violations[0]['code']);
        }
        $this->assertNull($s->find('Published'));
        $this->assertTrue($a->hasTags(['Draft']));
        try {
            $s->detach($a);
            $this->fail('Expected minimum rejection');
        } catch (PolicyViolation $e) {
            $this->assertSame('min_tags', $e->violations[0]['code']);
        }
        $this->assertSame(1, Assignment::count());
    }

    public function test_replace_is_atomic_and_preserves_unrelated_tags(): void
    {
        $s = $this->space();
        $a = $this->record();
        $a->attachTags(['Draft', 'News']);
        $this->policy(['min_tags' => 2, 'max_tags' => 2, 'exclusive' => [['Draft', 'Published']]]);
        $draft = $s->find('Draft');
        $result = $s->replace($a, ['Draft'], ['Published'], ['source' => 'editor']);
        $this->assertSame([$draft->id], $result['detached']);
        $this->assertSame([$s->find('Published')->id], $result['attached']);
        $this->assertTrue($a->hasTags(['Published', 'News']));
        $this->assertSame([], $s->validate($a));
    }

    public function test_required_tags_resolve_aliases_and_cannot_be_removed(): void
    {
        $s = $this->space();
        $a = $this->record();
        $tag = $s->create('Verified');
        $s->alias($tag, 'Trusted');
        $this->policy(['required' => ['Trusted']]);
        $a->syncTags(['Verified', 'News']);
        try {
            $a->syncTags(['News']);
            $this->fail('Expected required rejection');
        } catch (PolicyViolation $e) {
            $this->assertSame('required_tag', $e->violations[0]['code']);
        }
        $this->assertTrue($a->hasTags(['Verified', 'News']));
    }

    public function test_exclusive_groups_deduplicate_aliases_and_detect_conflicts(): void
    {
        $s = $this->space();
        $a = $this->record();
        $draft = $s->create('Draft');
        $s->alias($draft, 'Unpublished');
        $this->policy(['exclusive' => [['Draft', 'Unpublished', 'Published']]]);
        $a->attachTags(['Draft', 'Unpublished']);
        $this->assertSame([], $s->validate($a));
        try {
            $a->attachTags(['Published']);
            $this->fail('Expected exclusion');
        } catch (PolicyViolation $e) {
            $this->assertSame('exclusive_tags', $e->violations[0]['code']);
        }
        $this->assertSame(1, Assignment::count());
    }

    public function test_dependencies_are_checked_on_final_state(): void
    {
        $s = $this->space();
        $a = $this->record();
        $this->policy(['dependencies' => ['Premium' => ['Verified']]]);
        try {
            $a->attachTags(['Premium']);
            $this->fail('Expected dependency');
        } catch (PolicyViolation $e) {
            $this->assertSame('tag_dependency', $e->violations[0]['code']);
        }
        $a->syncTags(['Premium', 'Verified']);
        $this->assertSame([], $s->validate($a));
        $s->replace($a, ['Premium', 'Verified'], ['Basic']);
        $this->assertTrue($a->hasTags(['Basic']));
    }

    public function test_composed_transaction_allows_valid_status_swap_and_restores_after_failure(): void
    {
        $s = $this->space();
        $a = $this->record();
        $a->attachTags(['Draft']);
        $this->policy(['min_tags' => 1, 'max_tags' => 1]);
        $s->transaction($a, function () use ($a) {
            $a->detachTags(['Draft']);
            $a->attachTags(['Published']);
        });
        $this->assertTrue($a->hasTags(['Published']));
        try {
            $s->transaction($a, fn () => $a->detachTags());
            $this->fail('Expected rejection');
        } catch (PolicyViolation) {
        }
        $a->syncTags(['Draft']);
        $this->assertTrue($a->hasTags(['Draft'])); // Guard depth was cleaned after failure.
    }

    public function test_policy_applies_to_whole_toggle_without_rejecting_intermediate_state(): void
    {
        $a = $this->record();
        $a->attachTags(['Draft']);
        $this->policy(['min_tags' => 1, 'max_tags' => 1]);
        $a->toggleTags(['Draft', 'Published']);
        $this->assertTrue($a->hasTags(['Published']));
    }

    public function test_copy_validates_complete_batch_and_rolls_back_invalid_copy(): void
    {
        $s = $this->space();
        $from = $this->record();
        $to = $this->record();
        $from->attachTags(['A', 'B']);
        $this->policy(['min_tags' => 2, 'max_tags' => 2]);
        $report = $s->copy($from, $to);
        $this->assertCount(2, $report['copied']);
        $other = $this->record();
        config(['tagging.policies' => []]);
        $other->attachTags(['C']);
        $this->policy(['max_tags' => 2]);
        try {
            $s->copy($from, $other);
            $this->fail('Expected limit rejection');
        } catch (PolicyViolation) {
        }
        $this->assertSame(['C'], $s->tags($other)->pluck('name')->all());
    }

    public function test_automation_validates_complete_replacement_and_preserves_previous_on_failure(): void
    {
        $s = $this->space();
        $a = $this->record();
        $auto = $s->automation();
        $auto->apply($a, [new Rule('old', fn () => true, ['Draft'])]);
        $this->policy(['min_tags' => 1, 'max_tags' => 1, 'exclusive' => [['Draft', 'Published']]]);
        $auto->apply($a, [new Rule('new', fn () => true, ['Published'])]);
        $this->assertTrue($a->hasTags(['Published']));
        try {
            $auto->apply($a, []);
            $this->fail('Expected minimum rejection');
        } catch (PolicyViolation) {
        }
        $this->assertTrue($a->hasTags(['Published']));
    }

    public function test_preview_returns_policy_errors_without_writes_observers_or_domain_events(): void
    {
        $s = $this->space();
        $a = $this->record();
        $a->attachTags(['Draft']);
        $this->policy(['max_tags' => 1]);
        $events = 0;
        $models = 0;
        Event::listen(TagsChanged::class, function () use (&$events) {
            $events++;
        });
        Event::listen('eloquent.created: '.Tag::class, function () use (&$models) {
            $models++;
        });
        $invalid = $s->previewSync($a, ['A', 'B']);
        $this->assertFalse($invalid['valid']);
        $this->assertSame('max_tags', $invalid['violations'][0]['code']);
        $this->assertSame(['A', 'B'], $invalid['tags']);
        $valid = $s->previewSync($a, ['Published']);
        $this->assertTrue($valid['valid']);
        $this->assertTrue($a->hasTags(['Draft']));
        $this->assertSame(1, Tag::count());
        $this->assertSame(0, $events);
        $this->assertSame(0, $models);
        $a->syncTags(['Published']);
        $this->assertSame(1, $events); // Dispatcher and guard remain usable.
    }

    public function test_violations_do_not_dispatch_events_and_preserve_outer_transaction(): void
    {
        $s = $this->space();
        $a = $this->record();
        $a->attachTags(['Draft']);
        $this->policy(['max_tags' => 1]);
        $events = 0;
        Event::listen(TagsChanged::class, function () use (&$events) {
            $events++;
        });
        DB::transaction(function () use ($s, $a) {
            $this->record();
            try {
                $a->attachTags(['Published']);
                $this->fail('Expected rejection');
            } catch (PolicyViolation) {
            }
            $this->assertTrue($a->hasTags(['Draft']));
            $s->previewSync($a, ['Other']);
            $this->assertNull($s->find('Other'));
        });
        $this->assertSame(2, GovernedRecord::count());
        $this->assertSame(0, $events);
    }

    public function test_taxonomy_and_context_overrides_do_not_leak_tenant_assignments(): void
    {
        $a = $this->record();
        $this->policy(['max_tags' => 1, 'contexts' => ['seo' => ['max_tags' => 2]]], 'topics');
        $this->space('a', 'topics')->attach($a, ['A']);
        $this->space('b', 'topics')->attach($a, ['B']);
        $this->space('a', 'topics', 'seo')->attach($a, ['A', 'B']);
        $a->attachTags(['Unrestricted', 'Default']);
        $this->assertSame(6, Assignment::count());
        $this->assertSame([], $this->space('a', 'topics')->validate($a));
    }

    public function test_expired_and_inactive_tags_do_not_satisfy_requirements(): void
    {
        $s = $this->space();
        $a = $this->record();
        $a->attachTags(['Verified']);
        $this->policy(['required' => ['Verified']]);
        try {
            $a->attachTags(['Verified'], ['expires_at' => now()->subMinute()]);
            $this->fail('Expected expiry rejection');
        } catch (PolicyViolation) {
        }
        $this->assertNull(Assignment::first()->expires_at);
        $s->find('Verified')->update(['active' => false]);
        $this->assertSame('required_tag', $s->validate($a)[0]['code']);
    }

    public function test_invalid_policy_configuration_fails_without_mutation(): void
    {
        $a = $this->record();
        $this->policy(['max_tags' => 'one']);
        try {
            $a->attachTags(['New']);
            $this->fail('Expected invalid configuration');
        } catch (\InvalidArgumentException) {
        }
        $this->assertSame(0, Tag::count());
        $this->policy(['max_tags' => 1]);
        $a->attachTags(['Valid']);
        $this->assertTrue($a->hasTags(['Valid']));
    }
}

<?php

namespace Atannex\Tagging\Tests\Feature;

use Atannex\Tagging\Automation\Rule;
use Atannex\Tagging\Events\TagsChanged;
use Atannex\Tagging\Models\Assignment;
use Atannex\Tagging\Models\Tag;
use Atannex\Tagging\Tagging;
use Atannex\Tagging\TagSpace;
use Atannex\Tagging\Tests\Fixtures\Document;
use Atannex\Tagging\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;

class AutomationTest extends TestCase
{
    protected function defineSubjectTables(): void
    {
        Schema::create('documents', function (Blueprint $t) {
            $t->id();
            $t->string('title');
            $t->json('payload')->nullable();
            $t->timestamps();
        });
    }

    private function space(string $tenant = '', string $context = 'default'): TagSpace
    {
        return app(Tagging::class)->for($tenant, 'tags', $context);
    }

    private function document(string $title = 'Laravel and PHP'): Document
    {
        return Document::create(['title' => $title]);
    }

    public function test_literal_rules_preview_case_and_nested_attributes_without_writes(): void
    {
        $d = $this->document();
        $d->payload = ['summary' => 'A 100% framework'];
        $rules = [
            Rule::contains('both', ['title', 'payload.summary'], ['laravel', '100%'], ['Framework'], 'all', 0.8),
            Rule::contains('no-wildcards', ['title'], ['%'], ['Wildcard']),
            new Rule('callback', fn ($d) => $d->title !== '', ['Editorial']),
        ];
        $preview = $this->space()->automation()->preview($d, $rules);
        $this->assertSame(['both', 'callback'], array_column($preview, 'rule'));
        $this->assertSame(0.8, $preview[0]['weight']);
        $this->assertSame(0, Tag::count());
        $this->assertSame(0, Assignment::count());
    }

    public function test_automation_combines_aliases_weights_and_rule_provenance(): void
    {
        $s = $this->space();
        $d = $this->document();
        $tag = $s->create('PHP');
        $s->alias($tag, 'Language');
        $rules = [new Rule('first', fn () => true, ['PHP'], 0.4), new Rule('second', fn () => true, ['Language'], 0.9)];
        $report = $s->automation()->apply($d, $rules);
        $this->assertSame([$tag->id], $report['attached']);
        $this->assertSame(['first', 'second'], $report['matched_rules']);
        $row = Assignment::first();
        $this->assertSame('rules:default', $row->source);
        $this->assertSame(0.9, $row->weight);
        $this->assertSame(['rules' => ['first', 'second']], $row->metadata);
        $s->automation()->apply($d, $rules);
        $this->assertSame(1, Assignment::count());
    }

    public function test_automation_preserves_manual_assignments_and_other_sources_and_contexts(): void
    {
        $s = $this->space('a');
        $d = $this->document();
        $s->attach($d, ['Manual'], ['weight' => 0.3, 'metadata' => ['human' => true]]);
        $s->attach($d, ['Other'], ['source' => 'rules:other']);
        $s->attach($d, ['Stale'], ['source' => 'rules:editor']);
        $this->space('a', 'seo')->attach($d, ['SEO'], ['source' => 'rules:editor']);
        $this->space('b')->attach($d, ['Foreign'], ['source' => 'rules:editor']);
        $report = $s->automation('rules:editor')->apply($d, [new Rule('manual', fn () => true, ['Manual', 'New'])]);
        $this->assertSame([$s->find('Manual')->id], $report['preserved']);
        $this->assertSame([$s->find('Stale')->id], $report['detached']);
        $manual = $s->assignments($d)->where('tag_id', $s->find('Manual')->id)->first();
        $this->assertSame('manual', $manual->source);
        $this->assertSame(0.3, $manual->weight);
        $this->assertSame(['human' => true], $manual->metadata);
        $this->assertSame(5, Assignment::count());
    }

    public function test_append_mode_retains_stale_owned_tags_and_replace_removes_them(): void
    {
        $s = $this->space();
        $d = $this->document();
        $auto = $s->automation();
        $auto->apply($d, [new Rule('rule', fn () => true, ['Auto'])]);
        $auto->apply($d, [], replace: false);
        $this->assertSame(1, Assignment::count());
        $report = $auto->apply($d, []);
        $this->assertCount(1, $report['detached']);
        $this->assertSame(0, Assignment::count());
    }

    public function test_invalid_automation_rolls_back_vocabulary_and_events(): void
    {
        Event::fake([TagsChanged::class]);
        $s = $this->space();
        $d = $this->document();
        $s->create('Inactive', ['active' => false]);
        try {
            $s->automation()->apply($d, [new Rule('bad', fn () => true, ['New', 'Inactive'])]);
            $this->fail('Expected rejection');
        } catch (\InvalidArgumentException) {
        }
        $this->assertNull($s->find('New'));
        $this->assertSame(0, Assignment::count());
        Event::assertNotDispatched(TagsChanged::class);
    }

    public function test_duplicate_rule_names_and_limits_are_rejected(): void
    {
        $d = $this->document();
        $rule = new Rule('same', fn () => true, ['PHP']);
        try {
            $this->space()->automation()->preview($d, [$rule, $rule]);
            $this->fail('Expected duplicate rejection');
        } catch (\InvalidArgumentException) {
        }
        config(['tagging.max_automation_rules' => 1]);
        $this->expectException(\InvalidArgumentException::class);
        $this->space()->automation()->apply($d, [$rule, new Rule('other', fn () => false, ['Other'])]);
    }

    public function test_expired_owned_assignment_is_renewed_but_manual_expiration_is_preserved(): void
    {
        $s = $this->space();
        $d = $this->document();
        $s->attach($d, ['Auto'], ['source' => 'rules:default', 'expires_at' => now()->subDay()]);
        $s->attach($d, ['Manual'], ['expires_at' => now()->subDay()]);
        $s->automation()->apply($d, [new Rule('match', fn () => true, ['Auto', 'Manual'])]);
        $this->assertSame(['Auto'], $s->tags($d)->pluck('name')->all());
        $this->assertNotNull($s->assignments($d)->where('source', 'manual')->first()->expires_at);
    }

    public function test_copy_preserves_attributes_and_destination_conflicts_unless_overwritten(): void
    {
        $s = $this->space();
        $from = $this->document();
        $to = $this->document();
        $s->attach($from, ['PHP', 'Laravel'], ['source' => 'editor', 'weight' => 0.9, 'metadata' => ['reviewed' => true], 'expires_at' => now()->addDay()]);
        $s->attach($to, ['PHP', 'Existing'], ['weight' => 0.2]);
        $report = $s->copy($from, $to);
        $this->assertSame([$s->find('Laravel')->id], $report['copied']);
        $this->assertSame([$s->find('PHP')->id], $report['skipped']);
        $this->assertSame(0.2, $s->assignments($to)->where('tag_id', $s->find('PHP')->id)->first()->weight);
        $row = $s->assignments($to)->where('tag_id', $s->find('Laravel')->id)->first();
        $this->assertSame(0.9, $row->weight);
        $this->assertSame('editor', $row->source);
        $this->assertSame(['reviewed' => true], $row->metadata);
        $this->assertNotNull($row->expires_at);
        $s->copy($from, $to, overwrite: true);
        $this->assertSame(0.9, $s->assignments($to)->where('tag_id', $s->find('PHP')->id)->first()->weight);
        $this->assertTrue($s->has($to, ['Existing']));
    }

    public function test_copy_filters_expired_inactive_and_other_spaces_and_handles_self(): void
    {
        $s = $this->space('a');
        $from = $this->document();
        $to = $this->document();
        $s->attach($from, ['Live']);
        $s->attach($from, ['Expired'], ['expires_at' => now()->subDay()]);
        $s->attach($from, ['Inactive']);
        $s->find('Inactive')->update(['active' => false]);
        $this->space('b')->attach($from, ['Foreign']);
        $this->space('a', 'seo')->attach($from, ['SEO']);
        $s->copy($from, $to);
        $this->assertSame(['Live'], $s->tags($to)->pluck('name')->all());
        $this->assertSame(['copied' => [], 'skipped' => []], $s->copy($to, $to));
        $this->assertSame(1, $s->assignments($to)->count());
    }

    public function test_copy_rejects_oversized_batch_before_writing(): void
    {
        $s = $this->space();
        $from = $this->document();
        $to = $this->document();
        $s->attach($from, ['PHP', 'Laravel']);
        config(['tagging.max_tags_per_operation' => 1]);
        try {
            $s->copy($from, $to);
            $this->fail('Expected limit rejection');
        } catch (\InvalidArgumentException) {
        }
        $this->assertSame(0, $s->assignments($to)->count());
    }

    public function test_usage_counts_window_and_weight_with_source_context_and_tenant_filters(): void
    {
        $this->travelTo(now()->startOfSecond());
        $s = $this->space('a');
        $a = $this->document();
        $b = $this->document();
        $s->attach($a, ['PHP'], ['source' => 'editor', 'weight' => 0.5]);
        $s->attach($b, ['PHP'], ['source' => 'editor', 'weight' => 0.9]);
        $s->attach($a, ['SQL'], ['weight' => 2]);
        $s->attach($a, ['Expired'], ['expires_at' => now()->subMinute()]);
        $this->space('b')->attach($a, ['Foreign']);
        $this->space('a', 'seo')->attach($a, ['SEO']);
        $s->assignments($b)->update(['created_at' => now()->subDays(10)]);
        $rows = $s->usage(now()->subDay(), now()->addSecond());
        $this->assertSame(['SQL', 'PHP', 'Expired', 'SEO'], $rows->pluck('name')->all());
        $this->assertSame([1, 1, 0, 0], $rows->pluck('usage_count')->all());
        $this->assertEquals(2, $rows[0]->total_weight);
        $this->assertEquals(0.5, $rows[1]->total_weight);
        $filtered = $s->usage(now()->subDay(), now()->addSecond(), source: 'editor');
        $this->assertSame('PHP', $filtered->first()->name);
        $this->assertSame(1, $filtered->first()->usage_count);
        $boundary = $s->usage(now()->subDay(), now());
        $this->assertSame(0, $boundary->sum('usage_count'));
        $this->travelBack();
    }

    public function test_usage_rejects_reversed_windows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->space()->usage(now(), now()->subDay());
    }

    public function test_automation_source_cannot_claim_manual_assignments(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->space()->automation('manual');
    }
}

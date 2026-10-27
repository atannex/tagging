<?php

namespace Atannex\Tagging\Tests\Feature;

use Atannex\Tagging\Models\Tag;
use Atannex\Tagging\Tagging;
use Atannex\Tagging\TagSpace;
use Atannex\Tagging\Tests\Fixtures\CatalogItem;
use Atannex\Tagging\Tests\Fixtures\Entry;
use Atannex\Tagging\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DiscoveryTest extends TestCase
{
    protected function defineSubjectTables(): void
    {
        Schema::create('entries', function (Blueprint $t) {
            $t->id();
            $t->boolean('published')->default(true);
            $t->timestamps();
            $t->softDeletes();
        });
    }

    private function space(string $tenant = '', string $context = 'default'): TagSpace
    {
        return app(Tagging::class)->for($tenant, 'tags', $context);
    }

    private function entry(bool $published = true): Entry
    {
        return Entry::create(['published' => $published]);
    }

    public function test_matching_filters_weight_and_source_on_same_assignment(): void
    {
        $s = $this->space();
        $a = $this->entry();
        $b = $this->entry();
        $c = $this->entry();
        $s->attach($a, ['PHP'], ['weight' => 0.9, 'source' => 'editor']);
        $s->attach($b, ['PHP'], ['weight' => 0.2, 'source' => 'editor']);
        $s->attach($c, ['PHP'], ['weight' => 0.9, 'source' => 'rules:test']);
        $this->assertSame([$a->id], Entry::withTagMatch(['PHP'], minWeight: 0.8, source: 'editor')->pluck('id')->all());
        $this->assertSame([$b->id, $c->id], Entry::withTagMatch(['PHP'], mode: 'none', minWeight: 0.8, source: 'editor')->pluck('id')->all());
    }

    public function test_subtree_all_requires_each_branch_and_handles_overlapping_branches(): void
    {
        $s = $this->space();
        $root = $s->create('Engineering');
        $php = $s->create('PHP');
        $sql = $s->create('SQL');
        $other = $s->create('Editorial');
        $s->move($php, $root);
        $s->move($sql, $root);
        $s->alias($root, 'Development');
        $a = $this->entry();
        $b = $this->entry();
        $s->attach($a, [$php, $sql]);
        $s->attach($b, [$php, $other]);
        $this->assertSame(0, Entry::withTagMatch(['Engineering'])->count());
        $this->assertSame(2, Entry::withTagMatch(['Engineering'], descendants: true)->count());
        $this->assertSame([$b->id], Entry::withTagMatch(['Engineering', 'Editorial'], mode: 'all', descendants: true)->pluck('id')->all());
        $this->assertSame(2, Entry::withTagMatch(['Engineering', 'Development', 'PHP'], mode: 'all', descendants: true)->count());
    }

    public function test_matching_unknown_empty_and_invalid_inputs(): void
    {
        $this->entry()->attachTags(['PHP']);
        $this->assertSame(0, Entry::withTagMatch(['unknown'], mode: 'all')->count());
        $this->assertSame(0, Entry::withTagMatch([])->count());
        $this->assertSame(1, Entry::withTagMatch([], mode: 'all')->count());
        $this->assertSame(1, Entry::withTagMatch([], mode: 'none')->count());
        $this->assertSame(1, Tag::count());
        $this->expectException(\InvalidArgumentException::class);
        Entry::withTagMatch(['PHP'], minWeight: -1)->get();
    }

    public function test_matching_excludes_expired_inactive_and_foreign_assignments(): void
    {
        $a = $this->entry();
        $s = $this->space('a');
        $root = $s->create('Root');
        $child = $s->create('Child');
        $s->move($child, $root);
        $s->attach($a, [$child], ['expires_at' => now()->subDay()]);
        $this->space('b')->attach($a, ['Child']);
        $this->space('a', 'seo')->attach($a, ['Child']);
        $this->assertSame(0, Entry::withTagMatch(['Root'], $s, descendants: true)->count());
        $s->attach($a, [$child], ['expires_at' => null]);
        $this->assertSame(1, Entry::withTagMatch(['Root'], $s, descendants: true)->count());
        $child->update(['active' => false]);
        $this->assertSame(0, Entry::withTagMatch(['Root'], $s, descendants: true)->count());
    }

    public function test_similarity_ranks_shared_tags_then_weight_and_preserves_candidate_filters(): void
    {
        $s = $this->space();
        $seed = $this->entry();
        $a = $this->entry();
        $b = $this->entry();
        $c = $this->entry();
        $hidden = $this->entry(false);
        $s->attach($seed, ['PHP', 'Laravel'], ['weight' => 0.5]);
        $s->attach($a, ['PHP', 'Laravel'], ['weight' => 0.4]);
        $s->attach($b, ['PHP', 'Laravel'], ['weight' => 0.9]);
        $s->attach($c, ['PHP'], ['weight' => 5]);
        $s->attach($hidden, ['PHP', 'Laravel'], ['weight' => 100]);
        $result = $s->similar($seed, Entry::where('published', true)->orderBy('id'));
        $this->assertSame([$b->id, $a->id, $c->id], $result->modelKeys());
        $this->assertSame(2, (int) $result[0]->shared_tags);
        $this->assertEquals(0.9, $result[0]->similarity_weight);
        $this->assertSame([$b->id, $a->id], $s->similar($seed, Entry::where('published', true), minShared: 2)->modelKeys());
        $this->assertCount(1, $s->similar($seed, Entry::where('published', true), limit: 1));
    }

    public function test_similarity_respects_soft_deletes_expiry_and_space_boundaries(): void
    {
        $s = $this->space('a');
        $seed = $this->entry();
        $live = $this->entry();
        $expired = $this->entry();
        $deleted = $this->entry();
        $foreign = $this->entry();
        $s->attach($seed, ['PHP']);
        $s->attach($live, ['PHP']);
        $s->attach($expired, ['PHP'], ['expires_at' => now()->subDay()]);
        $s->attach($deleted, ['PHP']);
        $deleted->delete();
        $this->space('b')->attach($foreign, ['PHP']);
        $this->space('a', 'seo')->attach($foreign, ['PHP']);
        $this->assertSame([$live->id], $s->similar($seed, Entry::query())->modelKeys());
        $this->assertCount(2, $s->similar($seed, Entry::withTrashed()));
        $s->find('PHP')->update(['active' => false]);
        $this->assertCount(0, $s->similar($seed, Entry::query()));
    }

    public function test_similarity_empty_seed_and_invalid_threshold(): void
    {
        $seed = $this->entry();
        $this->entry()->attachTags(['PHP']);
        $this->assertCount(0, $this->space()->similar($seed, Entry::query()));
        $this->expectException(\InvalidArgumentException::class);
        $this->space()->similar($seed, Entry::query(), minShared: 0);
    }

    public function test_similarity_supports_uuid_candidates_of_a_different_model_type(): void
    {
        Schema::create('catalog_items', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->timestamps();
        });
        $s = $this->space();
        $seed = $this->entry();
        $item = CatalogItem::create(['id' => '019a1234-0000-7000-8000-123456789abc']);
        $s->attach($seed, ['PHP']);
        $s->attach($item, ['PHP']);
        $query = CatalogItem::query();
        $this->assertSame([$item->id], $s->similar($seed, $query)->modelKeys());
        $this->assertSame([], $query->getQuery()->joins ?? []); // The supplied builder is reusable.
        $this->assertSame([$seed->id], $s->similar($item, Entry::query())->modelKeys());
    }

    public function test_health_distinguishes_integrity_from_assignment_hygiene(): void
    {
        $s = $this->space();
        $a = $this->entry();
        $s->create('Unused');
        $s->attach($a, ['Live']);
        $s->attach($a, ['Expired'], ['expires_at' => now()->subDay()]);
        $s->attach($a, ['Inactive']);
        $s->find('Inactive')->update(['active' => false]);
        $health = $s->health();
        $this->assertTrue($health['healthy']);
        $this->assertSame([], $health['issues']);
        $this->assertSame(4, $health['tag_count']);
        $this->assertCount(2, $health['unused_tag_ids']);
        $this->assertSame(1, $health['inactive_assignment_count']);
        $this->assertSame(1, $health['expired_assignment_count']);
        $this->artisan('tagging:health')->expectsOutputToContain('4 tags; 0 integrity issues.')->assertSuccessful();
    }

    public function test_health_reports_missing_names_cycles_and_cross_space_parents_without_writes(): void
    {
        $s = $this->space('a');
        $a = $s->create('a');
        $b = $s->create('b');
        $c = $s->create('c');
        $foreign = $this->space('b')->create('foreign');
        DB::table('tagging_names')->where('tag_id', $a->id)->delete();
        DB::table('tagging_tags')->where('id', $a->id)->update(['parent_id' => $b->id]);
        DB::table('tagging_tags')->where('id', $b->id)->update(['parent_id' => $a->id]);
        DB::table('tagging_tags')->where('id', $c->id)->update(['parent_id' => $foreign->id]);
        $before = DB::table('tagging_tags')->get()->toJson();
        $health = $s->health();
        $this->assertFalse($health['healthy']);
        $this->assertEqualsCanonicalizing(['missing_canonical_name', 'hierarchy_cycle', 'invalid_parent_scope'], array_column($health['issues'], 'code'));
        $this->assertSame($before, DB::table('tagging_tags')->get()->toJson());
        $this->artisan('tagging:health', ['--tenant' => 'a', '--json' => true])->expectsOutputToContain('"healthy": false')->assertFailed();
    }

    public function test_health_detects_name_scope_mismatch_and_isolates_context_counts(): void
    {
        $s = $this->space('a');
        $a = $this->entry();
        $tag = $s->create('PHP');
        $this->space('a', 'seo')->attach($a, ['PHP'], ['expires_at' => now()->subDay()]);
        $this->assertSame(0, $s->health()['expired_assignment_count']);
        $this->assertSame(1, $this->space('a', 'seo')->health()['expired_assignment_count']);
        DB::table('tagging_names')->where('tag_id', $tag->id)->update(['tenant' => 'b']);
        $this->assertContains('name_scope_mismatch', array_column($s->health()['issues'], 'code'));
        $this->assertSame(0, $this->space('empty')->health()['tag_count']);
    }
}

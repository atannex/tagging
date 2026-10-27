<?php

namespace Atannex\Tagging\Tests\Feature;

use Atannex\Tagging\Events\TagStatusChanged;
use Atannex\Tagging\Models\Assignment;
use Atannex\Tagging\Models\Tag;
use Atannex\Tagging\Tagging;
use Atannex\Tagging\TagSpace;
use Atannex\Tagging\Tests\Fixtures\FacetArticle;
use Atannex\Tagging\Tests\Fixtures\FacetAsset;
use Atannex\Tagging\Tests\TestCase;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;

class FacetsTest extends TestCase
{
    protected function defineSubjectTables(): void
    {
        Schema::create('facet_articles', function (Blueprint $t) {
            $t->id();
            $t->boolean('published')->default(true);
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('facet_assets', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->timestamps();
        });
    }

    private function space(string $tenant = '', string $context = 'default'): TagSpace
    {
        return app(Tagging::class)->for($tenant, 'tags', $context);
    }

    private function article(bool $published = true): FacetArticle
    {
        return FacetArticle::create(['published' => $published]);
    }

    public function test_facets_count_filtered_subjects_and_weights(): void
    {
        $s = $this->space();
        $a = $this->article();
        $b = $this->article();
        $hidden = $this->article(false);
        $s->attach($a, ['PHP', 'Laravel'], ['weight' => 0.4]);
        $s->attach($b, ['PHP'], ['weight' => 0.8]);
        $s->attach($hidden, ['Hidden']);
        $rows = $s->facets(FacetArticle::where('published', true));
        $this->assertSame(['PHP', 'Laravel'], $rows->pluck('name')->all());
        $this->assertSame([2, 1], $rows->pluck('facet_count')->all());
        $this->assertEqualsWithDelta(1.2, $rows[0]->total_weight, 0.00001);
        $this->assertEquals(0.4, $rows[1]->total_weight);
        $this->assertCount(1, $s->facets(FacetArticle::where('published', true), limit: 1));
    }

    public function test_facets_ignore_pagination_deduplicate_joins_and_preserve_original_query(): void
    {
        $s = $this->space();
        $a = $this->article();
        $b = $this->article();
        $a->attachTags(['PHP', 'Laravel']);
        $b->attachTags(['PHP']);
        $query = FacetArticle::query()->join('tagging_assignments as joined', DB::getDriverName() === 'pgsql' ? DB::raw('CAST(facet_articles.id AS VARCHAR(64))') : 'facet_articles.id', '=', 'joined.taggable_id')
            ->where('joined.taggable_type', (new FacetArticle)->getMorphClass())->orderBy('facet_articles.id')->limit(1)->offset(1);
        $sql = $query->toSql();
        $rows = $s->facets($query);
        $this->assertSame([2, 1], $rows->pluck('facet_count')->all());
        $this->assertSame($sql, $query->toSql());
    }

    public function test_facets_honor_expiry_source_weight_soft_deletes_and_space_boundaries(): void
    {
        $s = $this->space('a');
        $a = $this->article();
        $b = $this->article();
        $deleted = $this->article();
        $s->attach($a, ['PHP'], ['source' => 'editor', 'weight' => 0.8]);
        $s->attach($b, ['PHP'], ['source' => 'rules:test', 'weight' => 0.9]);
        $s->attach($a, ['Low'], ['source' => 'editor', 'weight' => 0.1]);
        $s->attach($a, ['Expired'], ['source' => 'editor', 'expires_at' => now()->subDay()]);
        $s->attach($a, ['Inactive']);
        $s->find('Inactive')->update(['active' => false]);
        $s->attach($deleted, ['Deleted']);
        $deleted->delete();
        $this->space('b')->attach($a, ['Foreign']);
        $this->space('a', 'seo')->attach($a, ['SEO']);
        $rows = $s->facets(FacetArticle::query(), minWeight: 0.5, source: 'editor');
        $this->assertSame(['PHP'], $rows->pluck('name')->all());
        $this->assertSame(1, $rows[0]->facet_count);
        $this->assertNotContains('Deleted', $s->facets(FacetArticle::query())->pluck('name')->all());
        $this->assertContains('Deleted', $s->facets(FacetArticle::withTrashed())->pluck('name')->all());
    }

    public function test_facets_support_zero_counts_and_empty_candidates(): void
    {
        $s = $this->space();
        $s->create('Unused');
        $s->create('Disabled', ['active' => false]);
        $this->assertCount(0, $s->facets(FacetArticle::query()));
        $rows = $s->facets(FacetArticle::query(), includeUnused: true);
        $this->assertSame(['Unused'], $rows->pluck('name')->all());
        $this->assertSame(0, $rows[0]->facet_count);
        $this->assertEquals(0, $rows[0]->total_weight);
    }

    public function test_facets_support_uuid_keys_and_morph_maps(): void
    {
        Relation::morphMap(['facet-asset' => FacetAsset::class]);
        try {
            $asset = FacetAsset::create(['id' => '019a1234-0000-7000-8000-123456789abc']);
            $asset->attachTags(['Photo']);
            $this->assertSame(1, $this->space()->facets(FacetAsset::query())->first()->facet_count);
            $this->assertCount(0, $this->space()->facets(FacetArticle::query()));
        } finally {
            Relation::morphMap([], false);
        }
    }

    public function test_facets_reject_unsupported_queries_and_invalid_weights(): void
    {
        try {
            $this->space()->facets(FacetArticle::query()->groupBy('id'));
            $this->fail('Expected query rejection');
        } catch (\InvalidArgumentException) {
        }
        $this->expectException(\InvalidArgumentException::class);
        $this->space()->facets(FacetArticle::query(), minWeight: NAN);
    }

    public function test_deactivation_is_reversible_and_preserves_assignments_and_aliases(): void
    {
        $s = $this->space();
        $a = $this->article();
        $a->attachTags(['PHP']);
        $tag = $s->find('PHP');
        $s->alias($tag, 'Language');
        $this->assertSame([$tag->id], $s->deactivate($tag));
        $this->assertFalse($tag->active);
        $this->assertSame(1, Assignment::count());
        $this->assertCount(0, $s->tags($a));
        $this->assertCount(0, $s->facets(FacetArticle::query()));
        $this->assertSame([], $s->deactivate($tag));
        $this->assertSame([$tag->id], $s->activate($tag));
        $this->assertTrue($a->hasTags(['Language']));
    }

    public function test_cascades_are_explicit_and_keep_other_tenants_untouched(): void
    {
        $s = $this->space('a');
        $root = $s->create('Root');
        $child = $s->create('Child');
        $leaf = $s->create('Leaf');
        $s->move($child, $root);
        $s->move($leaf, $child);
        $foreign = $this->space('b')->create('Root');
        $s->deactivate($root);
        $this->assertTrue($child->fresh()->active);
        $this->assertSame([$child->id, $leaf->id], $s->deactivate($root, descendants: true));
        $this->assertTrue($foreign->fresh()->active);
        $this->assertSame([$root->id, $child->id, $leaf->id], $s->activate($root, descendants: true));
        $this->expectException(\InvalidArgumentException::class);
        $s->deactivate($foreign);
    }

    public function test_status_events_dispatch_after_commit_and_never_after_rollback(): void
    {
        $s = $this->space();
        $tag = $s->create('PHP');
        $events = [];
        Event::listen(TagStatusChanged::class, function ($event) use (&$events) {
            $events[] = $event;
        });
        DB::transaction(function () use ($s, $tag, &$events) {
            $s->deactivate($tag);
            $this->assertCount(0, $events);
        });
        $this->assertCount(1, $events);
        $this->assertTrue($events[0]->wasActive);
        $this->assertFalse($events[0]->isActive);
        try {
            DB::transaction(function () use ($s, $tag) {
                $s->activate($tag);
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {
        }
        $this->assertCount(1, $events);
        $this->assertFalse($tag->fresh()->active);
    }

    public function test_duplicate_suggestions_normalize_spaces_and_accents_without_merging(): void
    {
        $s = $this->space();
        $a = $s->create('Java Script');
        $b = $s->create('JavaScript');
        $c = $s->create('Café Shop');
        $d = $s->create('CafeShop');
        $s->create('Unrelated');
        $pairs = $s->potentialDuplicates(maxDistance: 0);
        $this->assertSame([[$a->id, $b->id], [$c->id, $d->id]], array_map(fn ($p) => [$p['left']['id'], $p['right']['id']], $pairs));
        $this->assertSame([0, 0], array_column($pairs, 'distance'));
        $this->assertSame(5, Tag::count());
    }

    public function test_duplicate_distance_is_unicode_aware_and_bounded(): void
    {
        $s = $this->space();
        $a = $s->create('中文');
        $b = $s->create('中問');
        $s->create('abcde');
        $s->create('abde');
        $s->create('xyzw');
        $pairs = $s->potentialDuplicates();
        $this->assertCount(2, $pairs);
        $this->assertSame($a->id, $pairs[0]['left']['id']);
        $this->assertSame($b->id, $pairs[0]['right']['id']);
        $this->assertSame([1, 1], array_column($pairs, 'distance'));
        $this->assertCount(0, $s->potentialDuplicates(maxDistance: 0));
        $this->assertCount(1, $s->potentialDuplicates(limit: 1));
    }

    public function test_duplicate_scan_isolates_tenant_and_inactive_tags_and_checks_limits(): void
    {
        $s = $this->space('a');
        $s->create('Java Script');
        $s->create('JavaScript', ['active' => false]);
        $this->space('b')->create('JavScript');
        $this->assertCount(0, $s->potentialDuplicates());
        $this->assertCount(1, $s->potentialDuplicates(includeInactive: true));
        config(['tagging.max_duplicate_scan_tags' => 1]);
        $this->expectException(\LengthException::class);
        $s->potentialDuplicates(includeInactive: true);
    }
}

<?php

namespace Atannex\Tagging\Tests\Feature;

use Atannex\Tagging\Events\TagsChanged;
use Atannex\Tagging\Models\Assignment;
use Atannex\Tagging\Models\Tag;
use Atannex\Tagging\Tagging;
use Atannex\Tagging\TagSpace;
use Atannex\Tagging\Tests\Fixtures\Article;
use Atannex\Tagging\Tests\Fixtures\Asset;
use Atannex\Tagging\Tests\TestCase;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;

class TaggingTest extends TestCase
{
    protected function defineSubjectTables(): void
    {
        Schema::create('articles', function (Blueprint $t) {
            $t->id();
            $t->string('title');
            $t->timestamps();
        });
    }

    private function article(): Article
    {
        return Article::create(['title' => 'Example']);
    }

    private function space(string $tenant = '', string $taxonomy = 'tags', string $context = 'default'): TagSpace
    {
        return app(Tagging::class)->for($tenant, $taxonomy, $context);
    }

    public function test_attach_is_idempotent_and_aliases_resolve(): void
    {
        $a = $this->article();
        $s = $this->space();
        $tag = $s->create('Laravel', ['translations' => ['fr' => 'Cadriciel']]);
        $s->alias($tag, 'Framework');
        $s->attach($a, ['Framework', 'Laravel'], ['weight' => 0.8, 'metadata' => ['reason' => 'editor']]);
        $s->attach($a, ['laravel']);
        $this->assertSame(1, Assignment::count());
        $this->assertSame(0.8, Assignment::first()->weight);
        $this->assertSame('Cadriciel', $tag->label('fr'));
        $this->assertSame('Laravel', $tag->label('es'));
        $this->assertSame(1, $s->tags($a)->count());
    }

    public function test_sync_and_detach_are_isolated_by_tenant_taxonomy_and_context(): void
    {
        $a = $this->article();
        foreach ([$this->space('a'), $this->space('b'), $this->space('a', 'topics'), $this->space('a', 'tags', 'seo')] as $s) {
            $s->attach($a, ['Laravel']);
        }
        $s = $this->space('a');
        $s->sync($a, ['PHP']);
        $this->assertSame(4, Assignment::count());
        $this->assertSame(['PHP'], $s->tags($a)->pluck('name')->all());
        $s->detach($a);
        $this->assertSame(3, Assignment::count());
        $this->expectException(\InvalidArgumentException::class);
        $s->attach($a, [$this->space('b')->find('Laravel')]);
    }

    public function test_query_modes_handle_unknown_duplicate_and_empty_inputs(): void
    {
        $a = $this->article();
        $a->attachTags(['PHP', 'Laravel']);
        $this->article()->attachTags(['PHP']);
        $this->assertSame(2, Article::withAnyTags(['PHP'])->count());
        $this->assertSame(1, Article::withAllTags(['PHP', 'Laravel', 'PHP'])->count());
        $this->assertSame(0, Article::withAllTags(['PHP', 'missing'])->count());
        $this->assertSame(1, Article::withoutTags(['Laravel'])->count());
        $this->assertSame(0, Article::withAnyTags([])->count());
        $this->assertSame(2, Article::withAllTags([])->count());
        $this->assertSame(2, Article::withoutTags([])->count());
        $this->assertSame(2, Tag::count());
    }

    public function test_expiry_inactivity_and_pruning(): void
    {
        $a = $this->article();
        $s = $this->space();
        $s->attach($a, ['old'], ['expires_at' => now()->subMinute()]);
        $s->attach($a, ['new']);
        $this->assertSame(['new'], $s->tags($a)->pluck('name')->all());
        $this->assertSame(0, Article::withAnyTags(['old'])->count());
        $this->artisan('tagging:prune --dry-run')->assertSuccessful();
        $this->assertSame(2, Assignment::count());
        $this->artisan('tagging:prune')->assertSuccessful();
        $this->assertSame(1, Assignment::count());
        $s->find('new')->update(['active' => false]);
        $this->assertCount(0, $s->tags($a));
        $this->assertSame(0, Article::withAnyTags(['new'])->count());
    }

    public function test_tree_and_cycle_prevention(): void
    {
        $s = $this->space();
        $a = $s->create('a');
        $b = $s->create('b');
        $c = $s->create('c');
        $s->move($b, $a);
        $s->move($c, $b);
        $this->assertEqualsCanonicalizing([$b->id, $c->id], $s->descendants($a)->modelKeys());
        $this->expectException(\InvalidArgumentException::class);
        $s->move($a, $c);
    }

    public function test_merge_preserves_aliases_children_and_target_weights(): void
    {
        $s = $this->space();
        $a = $this->article();
        $b = $this->article();
        $source = $s->create('JS');
        $target = $s->create('JavaScript');
        $child = $s->create('Node');
        $s->move($child, $source);
        $s->alias($source, 'ECMAScript');
        $s->attach($a, [$source], ['weight' => 0.2]);
        $s->attach($a, [$target], ['weight' => 0.9]);
        $s->attach($b, [$source]);
        $s->merge($source, $target);
        $this->assertSame(2, Assignment::count());
        $this->assertSame(0.9, $s->assignments($a)->first()->weight);
        $this->assertSame($target->id, $s->find('JS')->id);
        $this->assertSame($target->id, $s->find('ECMAScript')->id);
        $this->assertSame($target->id, $child->fresh()->parent_id);
    }

    public function test_atomic_rollback_on_invalid_batch(): void
    {
        $a = $this->article();
        $a->attachTags(['keep']);
        try {
            $a->syncTags(['created', '']);
            $this->fail('Expected rejection');
        } catch (\InvalidArgumentException) {
        }
        $this->assertSame(['keep'], $this->space()->tags($a)->pluck('name')->all());
        $this->assertNull($this->space()->find('created'));
    }

    public function test_events_cleanup_popularity_and_literal_suggestions(): void
    {
        Event::fake([TagsChanged::class]);
        $a = $this->article();
        $a->attachTags(['PHP', '100% pure']);
        $this->article()->attachTags(['PHP']);
        $this->assertSame('PHP', $this->space()->popular()->first()->name);
        $this->assertCount(1, $this->space()->suggest('100%'));
        Event::assertDispatched(TagsChanged::class, fn ($e) => $e->operation === 'attach' && count($e->tagIds) === 2);
        $a->delete();
        $this->assertSame(1, Assignment::count());
    }

    public function test_alias_collision_is_rejected(): void
    {
        $s = $this->space();
        $a = $s->create('a');
        $s->create('b');
        $this->expectException(\InvalidArgumentException::class);
        $s->alias($a, 'b');
    }

    public function test_unicode_names_have_stable_distinct_identities(): void
    {
        $s = $this->space();
        $a = $s->create('中文');
        $b = $s->create('日本語');
        $this->assertNotSame($a->id, $b->id);
        $this->assertSame($a->id, $s->find('中文')->id);
    }

    public function test_uuid_morph_map_and_soft_delete_restore(): void
    {
        Schema::create('assets', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->timestamps();
            $t->softDeletes();
        });
        Relation::morphMap(['asset' => Asset::class]);
        try {
            $asset = Asset::create(['id' => '019a1234-0000-7000-8000-123456789abc']);
            $asset->attachTags(['photo']);
            $this->assertSame('asset', Assignment::first()->taggable_type);
            $this->assertSame($asset->id, Assignment::first()->taggable->id);
            $asset->delete();
            $this->assertSame(1, Assignment::count());
            $asset->restore();
            $this->assertSame(1, Asset::withAnyTags(['photo'])->count());
            $asset->forceDelete();
            $this->assertSame(0, Assignment::count());
        } finally {
            Relation::morphMap([], false);
        }
    }

    public function test_explicit_space_queries_do_not_match_other_tenants(): void
    {
        $a = $this->article();
        $b = $this->article();
        $this->space('a')->attach($a, ['PHP']);
        $this->space('b')->attach($b, ['PHP']);
        $this->assertSame([$a->id], Article::withAllTags(['PHP'], $this->space('a'))->pluck('id')->all());
        $this->assertSame(0, Article::withAnyTags(['PHP'])->count());
    }

    public function test_invalid_weight_and_unsaved_subject_are_rejected(): void
    {
        $a = $this->article();
        try {
            $a->attachTags(['bad'], ['weight' => -1]);
            $this->fail('Expected rejection');
        } catch (\InvalidArgumentException) {
        }
        $this->assertSame(0, Tag::count());
        $this->expectException(\LogicException::class);
        (new Article)->attachTags(['unsaved']);
    }

    public function test_merge_into_descendant_is_rejected_without_data_loss(): void
    {
        $s = $this->space();
        $a = $s->create('parent');
        $b = $s->create('child');
        $s->move($b, $a);
        try {
            $s->merge($a, $b);
            $this->fail('Expected rejection');
        } catch (\InvalidArgumentException) {
        }
        $this->assertSame(2, Tag::count());
        $this->assertSame($a->id, $b->fresh()->parent_id);
    }
}

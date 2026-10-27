<?php

namespace Atannex\Tagging\Tests\Feature;

use Atannex\Tagging\Models\Assignment;
use Atannex\Tagging\Models\Tag;
use Atannex\Tagging\Tagging;
use Atannex\Tagging\TagSpace;
use Atannex\Tagging\Tests\Fixtures\OtherPremiumSubject;
use Atannex\Tagging\Tests\Fixtures\PremiumSubject;
use Atannex\Tagging\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;

class PremiumTest extends TestCase
{
    protected function defineSubjectTables(): void
    {
        Schema::create('premium_subjects', function (Blueprint $t) {
            $t->id();
            $t->timestamps();
        });
    }

    private function space(string $tenant = '', string $context = 'default'): TagSpace
    {
        return app(Tagging::class)->for($tenant, 'tags', $context);
    }

    private function subject(): PremiumSubject
    {
        return PremiumSubject::create();
    }

    public function test_rename_preserves_names_and_promotes_aliases(): void
    {
        $s = $this->space();
        $tag = $s->create('PHP');
        $s->alias($tag, 'Hypertext');
        $s->rename($tag, 'Hypertext');
        $this->assertSame('hypertext', $tag->slug);
        $this->assertSame($tag->id, $s->find('PHP')->id);
        $this->assertSame(['php'], $s->aliases($tag));
        $s->rename($tag, 'Modern PHP', keepOldName: false);
        $this->assertNull($s->find('Hypertext'));
        $this->assertTrue($s->removeAlias($tag, 'PHP'));
        $this->assertFalse($s->removeAlias($tag, 'missing'));
        $this->expectException(\InvalidArgumentException::class);
        $s->removeAlias($tag, 'Modern PHP');
    }

    public function test_rename_collision_preserves_assignments(): void
    {
        $s = $this->space();
        $a = $this->subject();
        $a->attachTags(['PHP', 'Laravel']);
        $tag = $s->find('PHP');
        try {
            $s->rename($tag, 'Laravel');
            $this->fail('Expected collision');
        } catch (\InvalidArgumentException) {
        }
        $this->assertSame('PHP', $tag->fresh()->name);
        $this->assertSame(2, Assignment::count());
    }

    public function test_ancestors_are_root_first_and_reject_foreign_tags(): void
    {
        $s = $this->space();
        $a = $s->create('root');
        $b = $s->create('branch');
        $c = $s->create('leaf');
        $s->move($b, $a);
        $s->move($c, $b);
        $this->assertSame([$a->id, $b->id], $s->ancestors($c)->modelKeys());
        $this->assertCount(0, $s->ancestors($a));
        $this->expectException(\InvalidArgumentException::class);
        $this->space('foreign')->ancestors($c);
    }

    public function test_toggle_deduplicates_aliases_and_renews_expired_assignments(): void
    {
        $s = $this->space();
        $a = $this->subject();
        $php = $s->create('PHP');
        $s->alias($php, 'language');
        $s->attach($a, ['PHP']);
        $s->attach($a, ['Laravel'], ['expires_at' => now()->subDay()]);
        $laravel = $s->find('Laravel');
        $this->assertSame(['attached' => [$laravel->id], 'detached' => [$php->id]], $a->toggleTags(['PHP', 'language', 'Laravel'], ['weight' => 0.7]));
        $this->assertTrue($a->hasTags(['Laravel']));
        $this->assertFalse($a->hasTags(['PHP']));
        $this->assertTrue($a->hasTags(['missing', 'Laravel'], 'any'));
        $this->assertFalse($a->hasTags(['missing', 'Laravel']));
        $this->assertTrue($a->hasTags([], 'all'));
        $this->assertFalse($a->hasTags([], 'any'));
        $this->assertTrue($a->hasTags([], 'none'));
        $this->assertNull(Assignment::first()->expires_at);
        $this->assertSame(0.7, Assignment::first()->weight);
    }

    public function test_toggle_rolls_back_detach_when_attach_fails(): void
    {
        $a = $this->subject();
        $a->attachTags(['keep']);
        try {
            $a->toggleTags(['keep', 'new'], ['weight' => -1]);
            $this->fail('Expected rejection');
        } catch (\InvalidArgumentException) {
        }
        $this->assertTrue($a->hasTags(['keep']));
        $this->assertNull($this->space()->find('new'));
    }

    public function test_related_counts_cooccurrence_with_space_and_expiry_isolation(): void
    {
        $s = $this->space('a');
        $a = $this->subject();
        $b = $this->subject();
        $s->attach($a, ['PHP', 'Laravel', 'SQL']);
        $s->attach($b, ['PHP', 'Laravel']);
        $s->attach($a, ['Expired'], ['expires_at' => now()->subDay()]);
        $s->attach($a, ['Inactive']);
        $s->find('Inactive')->update(['active' => false]);
        $this->space('b')->attach($a, ['foreign']);
        $this->space('a', 'seo')->attach($a, ['SEO']);
        $related = $s->related($s->find('PHP'));
        $this->assertSame(['Laravel', 'SQL'], $related->pluck('name')->all());
        $this->assertSame([2, 1], $related->pluck('shared_count')->map(fn ($n) => (int) $n)->all());
        $this->assertCount(1, $s->related($s->find('PHP'), 1));
        $this->assertCount(0, $s->related($s->find('Inactive')));
    }

    public function test_related_never_combines_equal_keys_of_different_model_types(): void
    {
        Schema::create('other_subjects', function (Blueprint $t) {
            $t->id();
            $t->timestamps();
        });
        $s = $this->space();
        $a = $this->subject();
        $other = OtherPremiumSubject::create();
        $this->assertSame($a->id, $other->id);
        $s->attach($a, ['Seed', 'Actual']);
        $s->attach($other, ['Unrelated']);
        $this->assertSame(['Actual'], $s->related($s->find('Seed'))->pluck('name')->all());
    }

    public function test_import_missing_parent_and_existing_alias_conflict_roll_back(): void
    {
        $s = $this->space('source');
        $s->create('new');
        $document = $s->export();
        $target = $this->space('target');
        $tag = $target->create('existing');
        $target->alias($tag, 'new');
        try {
            $target->import($document);
            $this->fail('Expected conflict');
        } catch (\InvalidArgumentException) {
        }
        $this->assertSame(1, $target->query()->count());
        $document['tags'][0]['parent'] = 'absent';
        $empty = $this->space('empty');
        try {
            $empty->import($document);
            $this->fail('Expected missing parent');
        } catch (\InvalidArgumentException) {
        }
        $this->assertSame(0, $empty->query()->count());
    }

    public function test_vocabulary_roundtrip_and_idempotent_updates(): void
    {
        $s = $this->space('source');
        $root = $s->create('Engineering');
        $tag = $s->create('PHP', ['metadata' => ['color' => 'purple'], 'translations' => ['fr' => 'Langage']]);
        $s->alias($tag, 'Language');
        $s->move($tag, $root);
        $s->attach($this->subject(), ['PHP']);
        $document = $s->export();
        $destination = $this->space('destination');
        $this->assertSame(['created' => 2, 'updated' => 0, 'dry_run' => false], $destination->import($document));
        $copy = $destination->find('Language');
        $this->assertSame(['color' => 'purple'], $copy->metadata);
        $this->assertSame('Langage', $copy->label('fr'));
        $this->assertSame('Engineering', $copy->parent->name);
        $this->assertSame($document, $destination->export());
        $this->assertSame(1, Assignment::count());
        $this->assertSame(['created' => 0, 'updated' => 2, 'dry_run' => false], $destination->import($document));
    }

    public function test_preview_rolls_back_without_observers_and_preserves_outer_transaction(): void
    {
        $s = $this->space('source');
        $s->create('PHP');
        $document = $s->export();
        $destination = $this->space('destination');
        $observed = 0;
        Event::listen('eloquent.created: '.Tag::class, function () use (&$observed) {
            $observed++;
        });
        DB::transaction(function () use ($destination, $document) {
            $destination->create('keep');
            $this->assertSame(['created' => 1, 'updated' => 0, 'dry_run' => true], $destination->import($document, true));
            $this->assertNull($destination->find('PHP'));
            $this->assertNotNull($destination->find('keep'));
        });
        $this->assertSame(1, $observed);
        $this->assertSame(1, $destination->query()->count());
    }

    public function test_alias_collision_and_cyclic_imports_are_atomic(): void
    {
        $s = $this->space('source');
        $s->create('a');
        $s->create('b');
        $document = $s->export();
        $destination = $this->space('destination');
        $bad = $document;
        $bad['tags'][0]['aliases'] = ['b'];
        try {
            $destination->import($bad);
            $this->fail('Expected collision');
        } catch (\InvalidArgumentException) {
        }
        $this->assertSame(0, $destination->query()->count());
        $bad = $document;
        $bad['tags'][0]['parent'] = 'b';
        $bad['tags'][1]['parent'] = 'a';
        try {
            $destination->import($bad);
            $this->fail('Expected cycle');
        } catch (\InvalidArgumentException) {
        }
        $this->assertSame(0, $destination->query()->count());
        $this->assertNull($destination->find('a'));
    }

    public function test_import_rearranges_tree_and_respects_limits(): void
    {
        $s = $this->space();
        $a = $s->create('a');
        $b = $s->create('b');
        $s->move($b, $a);
        $document = $s->export();
        $document['tags'][0]['parent'] = 'b';
        $document['tags'][1]['parent'] = null;
        $s->import($document);
        $this->assertSame($b->id, $a->fresh()->parent_id);
        $this->assertNull($b->fresh()->parent_id);
        config(['tagging.max_import_tags' => 1]);
        $this->expectException(\InvalidArgumentException::class);
        $s->import($document);
    }

    public function test_import_command_accepts_exact_byte_limit_and_rejects_oversized_files(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'tagging-growing-import-');
        try {
            $this->space()->create('PHP');
            $document = json_encode($this->space()->export(), JSON_THROW_ON_ERROR);
            file_put_contents($file, $document);
            config(['tagging.max_import_bytes' => strlen($document)]);
            $this->artisan('tagging:import', ['file' => $file, '--tenant' => 'copy'])->assertSuccessful();
            $this->assertSame(1, $this->space('copy')->query()->count());
            file_put_contents($file, $document.' ');
            clearstatcache(true, $file);
            $this->artisan('tagging:import', ['file' => $file, '--tenant' => 'copy'])
                ->expectsOutputToContain('Vocabulary file exceeds the configured size limit.')->assertFailed();
            $this->assertSame(1, $this->space('copy')->query()->count());
        } finally {
            unlink($file);
        }
    }

    public function test_commands_handle_preview_import_and_invalid_documents(): void
    {
        $this->space()->create('PHP');
        $file = tempnam(sys_get_temp_dir(), 'tagging-vocabulary-');
        try {
            file_put_contents($file, json_encode($this->space()->export(), JSON_THROW_ON_ERROR));
            $this->artisan('tagging:export')->expectsOutputToContain('"version": 1')->assertSuccessful();
            $this->artisan('tagging:import', ['file' => $file, '--tenant' => 'copy', '--dry-run' => true])
                ->expectsOutputToContain('Preview: 1 created, 0 updated.')->assertSuccessful();
            $this->assertSame(0, $this->space('copy')->query()->count());
            $this->artisan('tagging:import', ['file' => $file, '--tenant' => 'copy'])->assertSuccessful();
            $this->assertSame(1, $this->space('copy')->query()->count());
            file_put_contents($file, '{broken');
            $this->artisan('tagging:import', ['file' => $file])->assertFailed();
            file_put_contents($file, '{}');
            $this->artisan('tagging:import', ['file' => $file])->assertFailed();
        } finally {
            unlink($file);
        }
        $this->artisan('tagging:import', ['file' => '/missing/tagging.json'])->assertFailed();
    }
}

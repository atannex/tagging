<?php

namespace Atannex\Tagging;

use Atannex\Tagging\Automation\AutoTagger;
use Atannex\Tagging\Bulk\BulkTagger;
use Atannex\Tagging\Contracts\HierarchyStrategy;
use Atannex\Tagging\Events\TagsChanged;
use Atannex\Tagging\Exceptions\ImportFailure;
use Atannex\Tagging\Exceptions\PolicyRejected;
use Atannex\Tagging\Exceptions\ScopeViolation;
use Atannex\Tagging\Exceptions\VocabularyCollision;
use Atannex\Tagging\Governance\AssignmentGuard;
use Atannex\Tagging\Governance\AssignmentPolicy;
use Atannex\Tagging\Governance\AssignmentPreview;
use Atannex\Tagging\History\IncomingAssignment;
use Atannex\Tagging\History\Recorder;
use Atannex\Tagging\History\ReviewService;
use Atannex\Tagging\Models\Assignment;
use Atannex\Tagging\Models\Tag;
use Atannex\Tagging\Query\AssignmentFilter;
use Atannex\Tagging\Query\AssignmentLoader;
use Atannex\Tagging\Query\LoadedAssignments;
use Atannex\Tagging\Query\TagQueries;
use Atannex\Tagging\Support\Inputs;
use Atannex\Tagging\Support\SpaceTransactions;
use Atannex\Tagging\Support\TagAttributes;
use Atannex\Tagging\Taxonomy\Definition;
use Atannex\Tagging\Taxonomy\StateValidator;
use Atannex\Tagging\Tenancy\AssignmentVisibility;
use Atannex\Tagging\Tenancy\ScopeGuard;
use Atannex\Tagging\Vocabulary\DuplicateFinder;
use Atannex\Tagging\Vocabulary\TagLifecycle;
use Atannex\Tagging\Vocabulary\VocabularyHealth;
use Atannex\Tagging\Vocabulary\VocabularyLifecycle;
use Atannex\Tagging\Vocabulary\VocabularyTransfer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

final class TagSpace
{
    public readonly bool $tenantSelected;

    public readonly string $tenant;

    public function __construct(
        ?string $tenant = null,
        public readonly string $taxonomy = 'tags',
        public readonly string $context = 'default',
        ?bool $tenantSelected = null,
        public readonly ?string $actorId = null,
        public readonly ?string $operationId = null,
    ) {
        $this->tenant = $tenant ?? '';
        $this->tenantSelected = $tenantSelected ?? ($tenant !== null);
        foreach ([$this->tenant, $taxonomy, $context] as $value) {
            if (strlen($value) > 64) {
                throw new ScopeViolation('Context keys must be at most 64 bytes.');
            }
        }
        if ($taxonomy === '' || $context === '') {
            throw new ScopeViolation('Taxonomy and context cannot be empty.');
        }
        foreach ([$actorId, $operationId] as $id) {
            if ($id !== null && strlen($id) > 128) {
                throw new InvalidArgumentException('Actor and operation identifiers must be at most 128 bytes.');
            }
        }
        app(ScopeGuard::class)->space($this);
    }

    public function __serialize(): array
    {
        return ['version' => 2, 'tenant' => $this->tenant, 'taxonomy' => $this->taxonomy, 'context' => $this->context,
            'tenantSelected' => $this->tenantSelected, 'actorId' => $this->actorId, 'operationId' => $this->operationId];
    }

    public function __unserialize(array $data): void
    {
        if (($data['version'] ?? 1) !== 1 && ($data['version'] ?? 1) !== 2) {
            throw new ScopeViolation('Unsupported serialized TagSpace version.');
        }
        foreach (['tenant', 'taxonomy', 'context'] as $key) {
            if (! is_string($data[$key] ?? null)) {
                throw new ScopeViolation('Serialized space keys must be strings.');
            }
        }
        // A v1 nonempty tenant proves explicit selection. A legacy empty tenant cannot prove that intent in strict mode.
        $selected = $data['tenantSelected'] ?? ($data['tenant'] !== '');
        if (! is_bool($selected)) {
            throw new ScopeViolation('Invalid serialized tenant selection flag.');
        }
        foreach (['actorId', 'operationId'] as $key) {
            if (isset($data[$key]) && ! is_string($data[$key])) {
                throw new ScopeViolation('Serialized operation identifiers must be strings or null.');
            }
        }
        $validated = new self($data['tenant'], $data['taxonomy'], $data['context'], $selected, $data['actorId'] ?? null, $data['operationId'] ?? null);
        $this->tenant = $validated->tenant;
        $this->taxonomy = $validated->taxonomy;
        $this->context = $validated->context;
        $this->tenantSelected = $validated->tenantSelected;
        $this->actorId = $validated->actorId;
        $this->operationId = $validated->operationId;
    }

    /** @return Builder<Tag> */
    public function query(): Builder
    {
        app(ScopeGuard::class)->space($this);

        return Tag::query()->where('tenant', $this->tenant)->where('taxonomy', $this->taxonomy);
    }

    private function slug(string $name): string
    {
        $slug = Str::slug(trim($name));
        if ($slug === '') {
            $slug = 'u-'.substr(hash('sha256', mb_strtolower(trim($name))), 0, 40);
        }
        if (trim($name) === '' || mb_strlen($name) > 160 || strlen($slug) > 160) {
            throw new InvalidArgumentException('Tag names must contain 1–160 characters and produce a slug of at most 160 bytes.');
        }

        return $slug;
    }

    public function find(string|Tag $tag): ?Tag
    {
        app(ScopeGuard::class)->space($this);
        if ($tag instanceof Tag) {
            $this->assertTag($tag);

            return $this->query()->when(DB::transactionLevel() > 0, fn ($q) => $q->lockForUpdate())->find($tag->getKey());
        }
        $id = DB::table('tagging_names')->where('tenant', $this->tenant)->where('taxonomy', $this->taxonomy)
            ->where('slug', $this->slug($tag))->when(DB::transactionLevel() > 0, fn ($q) => $q->lockForUpdate())->value('tag_id');

        return $id ? $this->query()->when(DB::transactionLevel() > 0, fn ($q) => $q->lockForUpdate())->find($id) : null;
    }

    public function create(string $name, array $attributes = []): Tag
    {
        $slug = $this->slug($name);
        try {
            return app(SpaceTransactions::class)->run($this, function () use ($name, $attributes, $slug) {
                if ($existing = $this->find($name)) {
                    return $existing;
                }
                TagAttributes::validate($attributes);
                Definition::for($this)->metadata($this, $attributes['metadata'] ?? null);
                $tag = $this->query()->create([
                    'tenant' => $this->tenant, 'taxonomy' => $this->taxonomy, 'name' => trim($name), 'slug' => $slug,
                    ...array_intersect_key($attributes, array_flip(['translations', 'metadata', 'active'])),
                ])->refresh();
                DB::table('tagging_names')->insert(['tag_id' => $tag->id, 'tenant' => $this->tenant, 'taxonomy' => $this->taxonomy, 'slug' => $slug]);
                app(Recorder::class)->vocabulary($this, 'created', $tag->id, null, $tag->toArray());

                return $tag;
            });
        } catch (UniqueConstraintViolationException $exception) {
            // Only known identity indexes are recoverable. The failed savepoint has already rolled back.
            if (! str_contains($exception->getMessage(), 'tagging_identity') && ! str_contains($exception->getMessage(), 'tagging_name_identity')) {
                throw $exception;
            }
            if ($existing = $this->find($name)) {
                return $existing;
            }
            throw new VocabularyCollision('Canonical identity was claimed concurrently.', 0, $exception);
        }
    }

    public function alias(Tag $tag, string $alias): void
    {
        $this->assertTag($tag);
        $slug = $this->slug($alias);
        app(SpaceTransactions::class)->run($this, function () use ($tag, $alias, $slug) {
            $fresh = $this->find($tag) ?? throw new VocabularyCollision('Tag no longer exists.');
            if ($existing = $this->find($alias)) {
                if (! $existing->is($fresh)) {
                    throw new VocabularyCollision('This name already belongs to another tag.');
                }

                return;
            }
            DB::table('tagging_names')->insert(['tag_id' => $fresh->id, 'tenant' => $this->tenant, 'taxonomy' => $this->taxonomy, 'slug' => $slug]);
            app(Recorder::class)->vocabulary($this, 'alias_added', $fresh->id, null, ['alias' => $slug]);
        });
    }

    /** Canonical slug is excluded; aliases are returned in stable order. */
    public function aliases(Tag $tag): array
    {
        $this->assertTag($tag);
        $tag = $this->query()->findOrFail($tag->id);

        return DB::table('tagging_names')->where('tag_id', $tag->id)
            ->where('slug', '!=', $tag->slug)->orderBy('slug')->pluck('slug')->all();
    }

    public function removeAlias(Tag $tag, string $alias): bool
    {
        $this->assertTag($tag);

        return app(SpaceTransactions::class)->run($this, function () use ($tag, $alias) {
            $fresh = $this->query()->findOrFail($tag->id);
            $slug = $this->slug($alias);
            if ($slug === $fresh->slug) {
                throw new VocabularyCollision('The canonical name cannot be removed.');
            }
            $removed = DB::table('tagging_names')->where('tag_id', $fresh->id)->where('slug', $slug)->delete() > 0;
            if ($removed) {
                app(Recorder::class)->vocabulary($this, 'alias_removed', $fresh->id, ['alias' => $slug], null);
            }

            return $removed;
        });
    }

    /** Preserve old names by default so existing links and integrations still resolve. */
    public function rename(Tag $tag, string $name, bool $keepOldName = true): Tag
    {
        $this->assertTag($tag);
        $slug = $this->slug($name);

        return app(SpaceTransactions::class)->run($this, function () use ($tag, $name, $slug, $keepOldName) {
            $fresh = $this->query()->lockForUpdate()->findOrFail($tag->id);
            $existing = $this->find($name);
            if ($existing && ! $existing->is($fresh)) {
                throw new VocabularyCollision('This name already belongs to another tag.');
            }
            $before = $fresh->toArray();
            $oldSlug = $fresh->slug;
            $this->alias($fresh, $name);
            $fresh->update(['name' => trim($name), 'slug' => $slug]);
            if (! $keepOldName && $oldSlug !== $slug) {
                DB::table('tagging_names')->where('tag_id', $fresh->id)->where('slug', $oldSlug)->delete();
            }
            app(Recorder::class)->vocabulary($this, 'renamed', $fresh->id, $before, $fresh->fresh()->toArray());

            return $tag->refresh();
        });
    }

    /** Root-first path, excluding the tag itself. */
    public function ancestors(Tag $tag): Collection
    {
        $this->assertTag($tag);

        return app(HierarchyStrategy::class)->ancestors($this, $tag);
    }

    public function move(Tag $tag, ?Tag $parent): Tag
    {
        $this->assertTag($tag);
        if ($parent) {
            $this->assertTag($parent);
        }

        return app(SpaceTransactions::class)->run($this, function () use ($tag, $parent) {
            $fresh = $this->query()->findOrFail($tag->id);
            $before = $fresh->toArray();
            if ($parent) {
                $this->query()->findOrFail($parent->id);
            }
            $fresh->update(['parent_id' => $parent?->id]);
            app(StateValidator::class)->space($this);
            app(Recorder::class)->vocabulary($this, 'moved', $fresh->id, $before, $fresh->fresh()->toArray());

            return $tag->refresh();
        });
    }

    public function descendants(Tag $tag): Collection
    {
        $this->assertTag($tag);

        return app(HierarchyStrategy::class)->descendants($this, $tag);
    }

    private function assertTag(Tag $tag): void
    {
        if (! $tag->exists || $tag->tenant !== $this->tenant || $tag->taxonomy !== $this->taxonomy) {
            throw new ScopeViolation('Tag does not belong to this tenant and taxonomy.');
        }
    }

    private function subject(Model $model): void
    {
        app(ScopeGuard::class)->subject($this, $model);
        if (! $model->exists || $model->getKey() === null) {
            throw new LogicException('Save the subject before tagging it.');
        }
        if ($model->getConnection()->getName() !== (new Tag)->getConnection()->getName()) {
            throw new LogicException('Subjects and tagging must use the same database connection.');
        }
        if (strlen((string) $model->getKey()) > 64 || strlen($model->getMorphClass()) > 160) {
            throw new InvalidArgumentException('Subject key or morph type exceeds the storage limit; use a morph map.');
        }
    }

    private function resolve(iterable $tags): array
    {
        $ids = [];
        $count = 0;
        foreach ($tags as $input) {
            if (++$count > config('tagging.max_tags_per_operation', 100)) {
                throw new InvalidArgumentException('Too many tags in one operation.');
            }
            $tag = $this->assignmentTag($input);
            if (! $tag->active) {
                throw new InvalidArgumentException('Tag is missing or inactive.');
            }
            $ids[] = $tag->id;
        }

        return array_values(array_unique($ids));
    }

    /** @return Builder<Assignment> */
    public function assignments(Model $model): Builder
    {
        $this->subject($model);

        return Assignment::query()->where('taggable_type', $model->getMorphClass())->where('taggable_id', (string) $model->getKey())
            ->where('context', $this->context)->whereHas('tag', fn (Builder $q) => $q->where('tenant', $this->tenant)->where('taxonomy', $this->taxonomy));
    }

    /** @return Collection<int, Tag> */
    public function tags(Model $model): Collection
    {
        return $this->query()->where('active', true)->whereIn('id', $this->assignments($model)->current()->select('tag_id'))->get();
    }

    public function attach(Model $model, iterable $tags, array $attributes = []): void
    {
        $this->write($model, $tags, $attributes, false);
    }

    public function sync(Model $model, iterable $tags, array $attributes = []): void
    {
        $this->write($model, $tags, $attributes, true);
    }

    public function has(Model $model, iterable $tags, string $mode = 'all'): bool
    {
        if (! in_array($mode, ['any', 'all', 'none'], true)) {
            throw new InvalidArgumentException('Unknown matching mode.');
        }
        $current = $this->tags($model)->modelKeys();
        $ids = [];
        $missing = false;
        foreach ($tags as $input) {
            $tag = $this->find($input);
            if ($tag && $tag->active) {
                $ids[] = $tag->id;
            } else {
                $missing = true;
            }
        }
        $ids = array_unique($ids);
        $matches = count(array_intersect($current, $ids));

        return match ($mode) {
            'all' => ! $missing && $matches === count($ids),
            'any' => $matches > 0,
            'none' => $matches === 0,
        };
    }

    /** Expired assignments behave as absent and are renewed when toggled on. */
    public function toggle(Model $model, iterable $tags, array $attributes = []): array
    {
        $this->subject($model);
        $tags = Inputs::bounded($tags);

        return $this->transaction($model, function () use ($model, $tags, $attributes) {
            $model->newQuery()->whereKey($model->getKey())->lockForUpdate()->firstOrFail();
            $ids = $this->resolve($tags);
            $current = $this->tags($model)->modelKeys();
            $off = array_values(array_intersect($ids, $current));
            $on = array_values(array_diff($ids, $current));
            if ($off) {
                $this->detach($model, $this->query()->whereIn('id', $off)->get());
            }
            if ($on) {
                $this->attach($model, $this->query()->whereIn('id', $on)->get(), ['expires_at' => null, ...$attributes]);
            }

            return ['attached' => $on, 'detached' => $off];
        });
    }

    private function write(Model $model, iterable $tags, array $attributes, bool $sync): void
    {
        $this->subject($model);
        $tags = Inputs::bounded($tags);
        $attributes = array_intersect_key($attributes, array_flip(['weight', 'source', 'metadata', 'expires_at']));
        if (array_key_exists('weight', $attributes) && (! is_numeric($attributes['weight']) || ! is_finite((float) $attributes['weight']) || $attributes['weight'] < 0)) {
            throw new InvalidArgumentException('Weight must be finite and non-negative.');
        }
        if (array_key_exists('source', $attributes) && (! is_string($attributes['source']) || strlen($attributes['source']) > 64 || $attributes['source'] === '')) {
            throw new InvalidArgumentException('Source must contain 1–64 bytes.');
        }
        $this->transaction($model, function () use ($model, $tags, $attributes, $sync) {
            $model->newQuery()->whereKey($model->getKey())->lockForUpdate()->firstOrFail();
            $ids = $this->resolve($tags);
            if ($sync) {
                $this->assignments($model)->whereNotIn('tag_id', $ids)->delete();
            }
            foreach ($ids as $id) {
                $existing = $this->assignments($model)->where('tag_id', $id)->first();
                app(IncomingAssignment::class)->validate($this, $model, $id, $existing, $attributes);
                Assignment::query()->updateOrCreate([
                    'tag_id' => $id, 'taggable_type' => $model->getMorphClass(),
                    'taggable_id' => (string) $model->getKey(), 'context' => $this->context,
                ], $attributes);
            }
            event(new TagsChanged($model, $sync ? 'sync' : 'attach', $ids, $this->tenant, $this->taxonomy, $this->context));
        });
        $model->unsetRelation('tagAssignments');
    }

    public function detach(Model $model, ?iterable $tags = null): int
    {
        $tags = $tags === null ? null : Inputs::bounded($tags);

        return $this->transaction($model, function () use ($model, $tags) {
            $this->subject($model);
            $model->newQuery()->whereKey($model->getKey())->lockForUpdate()->firstOrFail();
            $query = $this->assignments($model);
            if ($tags !== null) {
                $ids = [];
                foreach ($tags as $input) {
                    if ($tag = $this->find($input)) {
                        $ids[] = $tag->id;
                    }
                }
                $query->whereIn('tag_id', $ids);
            }
            $ids = (clone $query)->pluck('tag_id')->all();
            $deleted = $query->delete();
            event(new TagsChanged($model, 'detach', $ids, $this->tenant, $this->taxonomy, $this->context));
            $model->unsetRelation('tagAssignments');

            return $deleted;
        });
    }

    /** Compose assignment operations and enforce policy on their final state. */
    public function transaction(Model $subject, \Closure $callback): mixed
    {
        $this->subject($subject);

        return app(AssignmentGuard::class)->run($this, $subject, $callback);
    }

    /** Return structured violations without modifying assignments. */
    public function validate(Model $subject): array
    {
        $this->subject($subject);
        $violations = AssignmentPolicy::for($this)->violations($this, $subject);
        try {
            app(StateValidator::class)->subject($this, $subject);
        } catch (PolicyRejected $exception) {
            $violations[] = ['code' => 'taxonomy_policy', 'message' => $exception->getMessage()];
        }

        return $violations;
    }

    public function previewSync(Model $subject, iterable $tags, array $attributes = []): array
    {
        $this->subject($subject);
        try {
            return $this->transaction($subject, function () use ($subject, $tags, $attributes) {
                Model::withoutEvents(function () use ($subject, $tags, $attributes) {
                    $this->sync($subject, $tags, $attributes);
                    $violations = $this->validate($subject);
                    throw new AssignmentPreview([
                        'valid' => $violations === [], 'violations' => $violations,
                        'tags' => $this->tags($subject)->pluck('name')->all(),
                    ]);
                });
            });
        } catch (AssignmentPreview $preview) {
            return $preview->report;
        }
    }

    /** Replace just the named assignments, retaining unrelated tags and validating final state. */
    public function replace(Model $subject, iterable $remove, iterable $add, array $attributes = []): array
    {
        $remove = Inputs::bounded($remove);
        $add = Inputs::bounded($add);

        return $this->transaction($subject, function () use ($subject, $remove, $add, $attributes) {
            $before = $this->assignments($subject)->pluck('tag_id')->all();
            $this->detach($subject, $remove);
            $ids = $this->resolve($add);
            if ($ids) {
                $this->attach($subject, $this->query()->whereIn('id', $ids)->get(), $attributes);
            }
            $after = $this->assignments($subject)->pluck('tag_id')->all();

            return ['attached' => array_values(array_diff($after, $before)), 'detached' => array_values(array_diff($before, $after))];
        });
    }

    /** Resolve names without creating tags; all/any/none semantics, including unknown names. */
    public function constrain(Builder $query, iterable $tags, string $mode = 'any'): Builder
    {
        $query = $this->scopeSubjects($query);
        if (! in_array($mode, ['any', 'all', 'none'], true)) {
            throw new InvalidArgumentException('Unknown matching mode.');
        }
        $ids = [];
        $missing = false;
        foreach ($tags as $input) {
            $tag = $this->find($input);
            if ($tag && $tag->active) {
                $ids[] = $tag->id;
            } else {
                $missing = true;
            }
        }
        $ids = array_values(array_unique($ids));
        $filter = fn (Builder $q) => $this->visibleAssignments((new AssignmentFilter)->apply($q))->where('context', $this->context)->whereIn('tag_id', $ids);
        if ($mode === 'all') {
            if ($missing) {
                return $query->whereRaw('1 = 0');
            }

            return $ids ? $query->whereHas('tagAssignments', $filter, '=', count($ids)) : $query;
        }

        return $mode === 'none' ? $query->whereDoesntHave('tagAssignments', $filter) : $query->whereHas('tagAssignments', $filter);
    }

    public function popular(int $limit = 20): Collection
    {
        return $this->query()->where('active', true)->withCount(['assignments as usage_count' => fn (Builder $q) => $this->visibleAssignments((new AssignmentFilter)->apply($q))->where('context', $this->context)])
            ->orderByDesc('usage_count')->orderBy('id')->limit(max(1, min($limit, 100)))->get();
    }

    public function suggest(string $prefix, int $limit = 10): Collection
    {
        // Escape LIKE metacharacters by using a portable explicit escape character.
        $prefix = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $prefix);

        return $this->query()->where('active', true)->whereRaw("name LIKE ? ESCAPE '!'", [$prefix.'%'])
            ->orderBy('name')->limit(max(1, min($limit, 100)))->get();
    }

    /** Co-occurrence on the same subject type AND key, in this context only. */
    public function related(Tag $tag, int $limit = 10): Collection
    {
        $this->assertTag($tag);
        $tag = $this->query()->findOrFail($tag->id);
        if (! $tag->active) {
            return new Collection;
        }
        $time = now();
        $scores = DB::table('tagging_assignments as candidate')
            ->join('tagging_assignments as seed', function ($join) use ($tag) {
                $join->on('candidate.taggable_type', '=', 'seed.taggable_type')
                    ->on('candidate.taggable_id', '=', 'seed.taggable_id')
                    ->on('candidate.context', '=', 'seed.context')->where('seed.tag_id', $tag->id);
            })
            ->where('candidate.context', $this->context)->where('candidate.tag_id', '!=', $tag->id)
            ->where(fn ($q) => $q->whereNull('candidate.expires_at')->orWhere('candidate.expires_at', '>', $time))
            ->where(fn ($q) => $q->whereNull('seed.expires_at')->orWhere('seed.expires_at', '>', $time))
            ->selectRaw('candidate.tag_id, COUNT(*) as shared_count')->groupBy('candidate.tag_id');
        app(AssignmentVisibility::class)->apply($this, $scores, 'candidate');

        return $this->query()->joinSub($scores, 'related_scores', 'tagging_tags.id', '=', 'related_scores.tag_id')
            ->where('active', true)->select('tagging_tags.*', 'related_scores.shared_count')
            ->orderByDesc('shared_count')->orderBy('tagging_tags.id')->limit(max(1, min($limit, 100)))->get();
    }

    public function automation(string $source = 'rules:default'): AutoTagger
    {
        return new AutoTagger($this, $source);
    }

    /** Copy current active assignments within this space, preserving target values by default. */
    public function copy(Model $from, Model $to, bool $overwrite = false): array
    {
        $this->subject($from);
        $this->subject($to);
        if ($from->getMorphClass() === $to->getMorphClass() && (string) $from->getKey() === (string) $to->getKey()) {
            return ['copied' => [], 'skipped' => []];
        }

        return app(AssignmentGuard::class)->run($this, $to, function () use ($from, $to, $overwrite) {
            // Stable ordering avoids opposite-direction copy operations locking subjects in opposite orders.
            $subjects = [$from, $to];
            usort($subjects, fn ($a, $b) => strcmp($a->getTable().'|'.$a->getKey(), $b->getTable().'|'.$b->getKey()));
            foreach ($subjects as $subject) {
                $locked = $subject->newQuery()->whereKey($subject->getKey())->lockForUpdate()->firstOrFail();
                app(ScopeGuard::class)->subject($this, $locked, false);
            }
            $rows = $this->assignments($from)->current()->whereHas('tag', fn (Builder $q) => $q->where('active', true))->with('tag')->get();
            if ($rows->count() > config('tagging.max_tags_per_operation', 100)) {
                throw new InvalidArgumentException('Too many assignments to copy.');
            }
            $existing = $this->assignments($to)->pluck('tag_id')->all();
            $copied = [];
            $skipped = [];
            foreach ($rows as $row) {
                if (! $overwrite && in_array($row->tag_id, $existing, true)) {
                    $skipped[] = $row->tag_id;

                    continue;
                }
                $this->attach($to, [$row->tag], [
                    'weight' => $row->weight, 'source' => $row->source,
                    'metadata' => $row->metadata, 'expires_at' => $row->expires_at,
                ]);
                $copied[] = $row->tag_id;
            }

            return ['copied' => $copied, 'skipped' => $skipped];
        }, lockSubject: false);
    }

    /** Rank surviving, current assignments first created within a half-open time window. */
    public function usage(\DateTimeInterface $since, ?\DateTimeInterface $until = null, int $limit = 20, ?string $source = null): Collection
    {
        $until ??= now();
        if ($since >= $until) {
            throw new InvalidArgumentException('Usage window end must follow its start.');
        }
        $window = fn (Builder $q) => $this->visibleAssignments((new AssignmentFilter)->apply($q))->where('context', $this->context)
            ->where('created_at', '>=', $since)->where('created_at', '<', $until)
            ->when($source !== null, fn (Builder $q) => $q->where('source', $source));

        return $this->query()->where('active', true)
            ->withCount(['assignments as usage_count' => $window])
            ->withSum(['assignments as total_weight' => $window], 'weight')
            ->orderByDesc('usage_count')->orderByDesc('total_weight')->orderBy('id')
            ->limit(max(1, min($limit, 100)))->get();
    }

    public function matching(Builder $query, iterable $tags, string $mode = 'any', ?float $minWeight = null, ?string $source = null, bool $descendants = false, ?float $maxWeight = null, bool $ancestors = false, bool $includeRoot = true, string $expiration = 'current'): Builder
    {
        return (new TagQueries($this))->matching($query, $tags, $mode, $minWeight, $source, $descendants, $maxWeight, $ancestors, $includeRoot, $expiration);
    }

    public function similar(Model $subject, Builder $candidates, int $limit = 10, int $minShared = 1): Collection
    {
        return (new TagQueries($this))->similar($subject, $candidates, $limit, $minShared);
    }

    public function health(): array
    {
        return (new VocabularyHealth($this))->inspect();
    }

    public function facets(Builder $candidates, int $limit = 20, ?float $minWeight = null, ?string $source = null, bool $includeUnused = false): Collection
    {
        return (new TagQueries($this))->facets($candidates, $limit, $minWeight, $source, $includeUnused);
    }

    public function activate(Tag $tag, bool $descendants = false): array
    {
        return (new TagLifecycle($this))->setActive($tag, true, $descendants);
    }

    public function deactivate(Tag $tag, bool $descendants = false): array
    {
        return (new TagLifecycle($this))->setActive($tag, false, $descendants);
    }

    public function potentialDuplicates(int $maxDistance = 1, int $limit = 20, bool $includeInactive = false): array
    {
        return (new DuplicateFinder($this))->find($maxDistance, $limit, $includeInactive);
    }

    public function bulk(Builder $candidates, iterable $tags, string $operation = 'attach', array $attributes = [], bool $dryRun = false, bool $atomic = true): array
    {
        return (new BulkTagger($this))->run($candidates, $tags, $operation, $attributes, $dryRun, $atomic);
    }

    public function normalizeName(string $name): string
    {
        return $this->slug($name);
    }

    /** @param Builder<Assignment> $query
     * @return Builder<Assignment>
     */
    public function visibleAssignments(Builder $query): Builder
    {
        app(AssignmentVisibility::class)->apply($this, $query);

        return $query;
    }

    public function withOperation(?string $actorId = null, ?string $operationId = null): self
    {
        return new self($this->tenant, $this->taxonomy, $this->context, $this->tenantSelected, $actorId, $operationId);
    }

    public function scopeSubjects(Builder $query): Builder
    {
        return app(ScopeGuard::class)->query($this, $query);
    }

    public function assignmentTag(string|Tag $input): Tag
    {
        $definition = Definition::for($this);
        $tag = $this->find($input);
        if (! $tag && is_string($input) && $definition->options['auto_create']) {
            $tag = $this->create($input);
        }
        if (! $tag || ! $tag->active) {
            throw new PolicyRejected('Tag is missing, inactive, or automatic vocabulary creation is disabled.');
        }
        if ($definition->options['approved_only'] && $tag->approved_at === null) {
            throw new PolicyRejected('Tag must be approved before assignment.');
        }

        return $tag;
    }

    public function approve(Tag $tag): Tag
    {
        $this->assertTag($tag);

        return app(SpaceTransactions::class)->run($this, function () use ($tag) {
            $fresh = $this->query()->findOrFail($tag->id);
            $before = $fresh->toArray();
            if ($fresh->approved_at === null) {
                $fresh->update(['approved_at' => now()]);
            }
            app(Recorder::class)->vocabulary($this, 'approved', $fresh->id, $before, $fresh->fresh()->toArray());

            return $tag->refresh();
        });
    }

    public function delete(Tag $tag, ?string $children = null, ?string $assignments = null): void
    {
        $this->assertTag($tag);
        (new VocabularyLifecycle($this))->delete($tag, $children, $assignments);
    }

    public function decide(Model $subject, iterable $tags, string $decision): void
    {
        (new ReviewService($this))->decide($subject, $tags, $decision);
    }

    public function currentAssignments(Model $subject, ?AssignmentFilter $filter = null): Builder
    {
        $filter ??= new AssignmentFilter;
        $query = $this->assignments($subject);
        if (! $filter->includeInactive) {
            $query->whereHas('tag', fn ($q) => $q->where('active', true));
        }

        return $filter->apply($query, true);
    }

    public function eagerLoad(iterable $subjects, ?AssignmentFilter $filter = null): LoadedAssignments
    {
        app(ScopeGuard::class)->space($this);

        return (new AssignmentLoader)->load($this, $subjects, $filter);
    }

    public function export(int $version = 1): array
    {
        if (! in_array($version, [1, 2], true)) {
            throw new ImportFailure('Unsupported export version.');
        }
        $tags = $this->query()->orderBy('id')->get();
        $slugs = $tags->pluck('slug', 'id');
        $names = DB::table('tagging_names')->where('tenant', $this->tenant)->where('taxonomy', $this->taxonomy)
            ->orderBy('slug')->get()->groupBy('tag_id');

        return ['version' => $version, 'tags' => $tags->map(fn (Tag $tag) => [
            'name' => $tag->name, 'slug' => $tag->slug,
            'parent' => $tag->parent_id ? $slugs->get($tag->parent_id) : null,
            'aliases' => ($names->get($tag->id) ?? collect())->pluck('slug')->reject(fn ($slug) => $slug === $tag->slug)->values()->all(),
            'translations' => $tag->translations ?? [], 'metadata' => $tag->metadata ?? [], 'active' => $tag->active,
            ...($version === 2 ? ['approved' => $tag->approved_at !== null] : []),
        ])->all()];
    }

    /** Import vocabulary into this space; assignments and tenant identifiers are never imported. */
    public function import(array $document, bool $dryRun = false, string $conflict = 'update'): array
    {
        return (new VocabularyTransfer($this))->import($document, $dryRun, $conflict);
    }

    /** Merge vocabulary globally within the taxonomy, preserving target pivot values on conflicts. */
    public function merge(Tag $source, Tag $target): Tag
    {
        $this->assertTag($source);
        $this->assertTag($target);

        return (new VocabularyLifecycle($this))->merge($source, $target);
    }
}

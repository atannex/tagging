<?php

namespace Atannex\Tagging\Concerns;

use Atannex\Tagging\Models\Assignment;
use Atannex\Tagging\Operations\Maintenance;
use Atannex\Tagging\Relations\StringKeyMorphMany;
use Atannex\Tagging\TagSpace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasTags
{
    public static function bootHasTags(): void
    {
        static::deleting(function ($model) {
            if (! method_exists($model, 'isForceDeleting') || $model->isForceDeleting()) {
                app(Maintenance::class)->cleanup($model);
            }
        });
    }

    /** Raw relationship for eager loading; includes expired/inactive assignments. */
    public function tagAssignments(): MorphMany
    {
        $instance = $this->newRelatedInstance(Assignment::class);

        return new StringKeyMorphMany($instance->newQuery(), $this, $instance->qualifyColumn('taggable_type'), $instance->qualifyColumn('taggable_id'), $this->getKeyName());
    }

    public function tagSpace(string $taxonomy = 'tags', string $context = 'default', string $tenant = ''): TagSpace
    {
        return new TagSpace($tenant, $taxonomy, $context, func_num_args() >= 3);
    }

    public function attachTags(iterable $tags, array $attributes = []): static
    {
        $this->tagSpace()->attach($this, $tags, $attributes);

        return $this;
    }

    public function syncTags(iterable $tags, array $attributes = []): static
    {
        $this->tagSpace()->sync($this, $tags, $attributes);

        return $this;
    }

    public function detachTags(?iterable $tags = null): static
    {
        $this->tagSpace()->detach($this, $tags);

        return $this;
    }

    public function toggleTags(iterable $tags, array $attributes = []): array
    {
        return $this->tagSpace()->toggle($this, $tags, $attributes);
    }

    public function hasTags(iterable $tags, string $mode = 'all'): bool
    {
        return $this->tagSpace()->has($this, $tags, $mode);
    }

    public function replaceTags(iterable $remove, iterable $add, array $attributes = []): array
    {
        return $this->tagSpace()->replace($this, $remove, $add, $attributes);
    }

    public function scopeWithTagMatch(Builder $query, iterable $tags, ?TagSpace $space = null, string $mode = 'any', ?float $minWeight = null, ?string $source = null, bool $descendants = false, ?float $maxWeight = null, bool $ancestors = false, bool $includeRoot = true, string $expiration = 'current'): Builder
    {
        return ($space ?? $this->tagSpace())->matching($query, $tags, $mode, $minWeight, $source, $descendants, $maxWeight, $ancestors, $includeRoot, $expiration);
    }

    public function scopeWithAnyTags(Builder $query, iterable $tags, ?TagSpace $space = null): Builder
    {
        return ($space ?? $this->tagSpace())->constrain($query, $tags, 'any');
    }

    public function scopeWithAllTags(Builder $query, iterable $tags, ?TagSpace $space = null): Builder
    {
        return ($space ?? $this->tagSpace())->constrain($query, $tags, 'all');
    }

    public function scopeWithoutTags(Builder $query, iterable $tags, ?TagSpace $space = null): Builder
    {
        return ($space ?? $this->tagSpace())->constrain($query, $tags, 'none');
    }
}

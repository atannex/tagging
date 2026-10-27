<?php

namespace Atannex\Tagging\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $tenant
 * @property string $taxonomy
 * @property string $name
 * @property string $slug
 * @property int|null $parent_id
 * @property array|null $translations
 * @property array|null $metadata
 * @property bool $active
 * @property CarbonImmutable|null $approved_at
 */
class Tag extends Model
{
    protected $table = 'tagging_tags';

    protected $guarded = ['id'];

    protected $casts = ['translations' => 'array', 'metadata' => 'array', 'active' => 'boolean', 'approved_at' => 'immutable_datetime'];

    /** @return BelongsTo<Tag, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<Tag, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** @return HasMany<Assignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(Assignment::class, 'tag_id');
    }

    public function label(?string $locale = null): string
    {
        return $this->translations[$locale ?? app()->getLocale()] ?? $this->name;
    }
}

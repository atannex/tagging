<?php

namespace Atannex\Tagging\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property int $id
 * @property int $tag_id
 * @property string $taggable_type
 * @property string $taggable_id
 * @property string $context
 * @property float $weight
 * @property string $source
 * @property array|null $metadata
 * @property CarbonImmutable|null $expires_at
 * @property-read Tag|null $tag
 */
class Assignment extends Model
{
    protected $table = 'tagging_assignments';

    protected $guarded = ['id'];

    protected $casts = ['metadata' => 'array', 'weight' => 'float', 'expires_at' => 'immutable_datetime'];

    /** @return BelongsTo<Tag, $this> */
    public function tag(): BelongsTo
    {
        return $this->belongsTo(Tag::class);
    }

    public function taggable(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }
}

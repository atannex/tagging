<?php

namespace Atannex\Tagging\Query;

use Atannex\Tagging\Exceptions\ScopeViolation;
use Atannex\Tagging\Models\Assignment;
use Atannex\Tagging\Models\Tag;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

final class LoadedAssignments
{
    /** @param array<string, Collection<int, Assignment>> $rows */
    public function __construct(private readonly array $rows) {}

    public static function key(Model $model): string
    {
        return json_encode([$model->getConnection()->getName(), $model->getMorphClass(), (string) $model->getKey()], JSON_THROW_ON_ERROR);
    }

    /** @return Collection<int, Assignment> */
    public function assignments(Model $model): Collection
    {
        return $this->rows[self::key($model)] ?? throw new ScopeViolation('This model was not included in the eager-load snapshot.');
    }

    public function tags(Model $model): Collection
    {
        return new Collection($this->assignments($model)->map(fn ($a) => $a->getRelations()['tag'] ?? null)->filter(fn ($tag) => $tag instanceof Tag)->values()->all());
    }
}

<?php

namespace Atannex\Tagging\Relations;

use Atannex\Tagging\Models\Assignment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Query\Expression;

/**
 * Preserve raw MorphMany semantics while comparing heterogeneous subject keys portably.
 *
 * @template TDeclaringModel of Model
 *
 * @extends MorphMany<Assignment, TDeclaringModel>
 */
class StringKeyMorphMany extends MorphMany
{
    public function getRelationExistenceQuery(Builder $query, Builder $parentQuery, $columns = ['*'])
    {
        $query = parent::getRelationExistenceQuery($query, $parentQuery, $columns);
        $driver = $query->getModel()->getConnection()->getDriverName();
        $cast = in_array($driver, ['mysql', 'mariadb'], true) ? 'CHAR(64)' : 'VARCHAR(64)';
        foreach ($query->getQuery()->wheres as &$where) {
            if (($where['type'] ?? null) === 'Column' && $where['first'] === $this->getQualifiedParentKeyName()) {
                $where['first'] = new Expression('CAST('.$query->getQuery()->getGrammar()->wrap($where['first']).' AS '.$cast.')');
            }
        }

        return $query;
    }

    protected function whereInMethod(Model $model, $key)
    {
        return 'whereIn';
    }

    protected function getKeys(array $models, $key = null)
    {
        return array_map('strval', parent::getKeys($models, $key));
    }
}

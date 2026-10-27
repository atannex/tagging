<?php

namespace Atannex\Tagging\Tenancy;

use Atannex\Tagging\TagSpace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

final class AssignmentVisibility
{
    /** Scope aggregate APIs that do not receive a concrete subject or candidate model. */
    public function apply(TagSpace $space, Builder|QueryBuilder $query, string $prefix = 'tagging_assignments'): Builder|QueryBuilder
    {
        if (! config('tagging.subject_access')) {
            return $query;
        }
        $types = DB::table('tagging_assignments')->whereIn('tag_id', $space->query()->select('id'))->distinct()->pluck('taggable_type');

        return $query->where(function ($q) use ($space, $types, $prefix) {
            $q->whereRaw('1 = 0');
            foreach ($types as $type) {
                $class = Relation::getMorphedModel($type) ?? $type;
                if (! is_subclass_of($class, Model::class)) {
                    continue;
                }
                $subjects = $space->scopeSubjects((new $class)->newQuery());
                $connection = $subjects->getModel()->getConnection();
                $cast = in_array($connection->getDriverName(), ['mysql', 'mariadb'], true) ? 'CHAR(64)' : 'VARCHAR(64)';
                $key = $connection->getQueryGrammar()->wrap($subjects->getModel()->getQualifiedKeyName());
                $subjects->select([])->selectRaw('CAST('.$key.' AS '.$cast.')');
                $q->orWhere(fn ($q) => $q->where($prefix.'.taggable_type', $type)->whereIn($prefix.'.taggable_id', $subjects));
            }
        });
    }
}

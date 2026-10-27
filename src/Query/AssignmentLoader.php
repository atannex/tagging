<?php

namespace Atannex\Tagging\Query;

use Atannex\Tagging\Exceptions\ScopeViolation;
use Atannex\Tagging\Models\Assignment;
use Atannex\Tagging\TagSpace;
use Atannex\Tagging\Tenancy\ScopeGuard;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

final class AssignmentLoader
{
    public function load(TagSpace $space, iterable $subjects, ?AssignmentFilter $filter = null): LoadedAssignments
    {
        $filter ??= new AssignmentFilter;
        $rows = [];
        $batch = [];
        foreach ($subjects as $subject) {
            if (! $subject instanceof Model || ! $subject->exists) {
                throw new ScopeViolation('Eager loading requires saved Eloquent models.');
            }
            app(ScopeGuard::class)->subject($space, $subject, false);
            $key = LoadedAssignments::key($subject);
            $rows[$key] ??= new Collection;
            $batch[$key] = $subject;
            if (count($batch) === 500) {
                $this->batch($space, $filter, $batch, $rows);
                $batch = [];
            }
        }
        if ($batch) {
            $this->batch($space, $filter, $batch, $rows);
        }

        return new LoadedAssignments($rows);
    }

    private function batch(TagSpace $space, AssignmentFilter $filter, array $subjects, array &$rows): void
    {
        $types = [];
        $lookup = [];
        $prototypes = [];
        foreach ($subjects as $key => $model) {
            $types[$model->getMorphClass()][] = (string) $model->getKey();
            $prototypes[$model->getMorphClass()] = $model;
            $lookup[$model->getMorphClass()][(string) $model->getKey()] = $key;
        }
        if (config('tagging.subject_access')) {
            foreach ($types as $type => $keys) {
                $prototype = $prototypes[$type];
                $allowed = $space->scopeSubjects($prototype->newQuery())->whereKey($keys)->pluck($prototype->getQualifiedKeyName())->map('strval')->all();
                if (array_diff($keys, $allowed)) {
                    throw new ScopeViolation('Eager-load subjects no longer match this tenant/space.');
                }
            }
        }
        $query = Assignment::query()->where('context', $space->context)->whereHas('tag', function ($q) use ($space, $filter) {
            $q->where('tenant', $space->tenant)->where('taxonomy', $space->taxonomy);
            if (! $filter->includeInactive) {
                $q->where('active', true);
            }
        })->where(function ($q) use ($types) {
            foreach ($types as $type => $keys) {
                $q->orWhere(fn ($q) => $q->where('taggable_type', $type)->whereIn('taggable_id', $keys));
            }
        })->with('tag');
        foreach ($filter->apply($query, true)->get() as $assignment) {
            // Eager loading spans two statements: a tag may disappear or change activity between them.
            $tag = $assignment->getRelation('tag');
            if (! $tag || $tag->tenant !== $space->tenant || $tag->taxonomy !== $space->taxonomy || (! $filter->includeInactive && ! $tag->active)) {
                continue;
            }

            $key = $lookup[$assignment->taggable_type][(string) $assignment->taggable_id];
            $rows[$key]->push($assignment);
        }
    }
}

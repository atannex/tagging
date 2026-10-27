<?php

namespace Atannex\Tagging\Taxonomy;

use Atannex\Tagging\Exceptions\HierarchyConflict;
use Atannex\Tagging\Exceptions\PolicyRejected;
use Atannex\Tagging\Governance\PolicyViolation;
use Atannex\Tagging\TagSpace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;

final class StateValidator
{
    public function subject(TagSpace $space, Model $subject): void
    {
        $definition = Definition::for($space);
        $options = $definition->options;
        if ($options['selection'] === 'multiple' && $options['max_assignments'] === null && ! $options['approved_only'] && $options['assign_parents']) {
            return;
        }
        $tags = $space->tags($subject);
        $maximum = $options['selection'] === 'single' ? 1 : $options['max_assignments'];
        if ($options['max_assignments'] !== null) {
            $maximum = min($maximum ?? PHP_INT_MAX, $options['max_assignments']);
        }
        if ($maximum !== null && $tags->count() > $maximum) {
            throw new PolicyRejected('Current assignment count exceeds the taxonomy limit of '.$maximum.'.');
        }
        if ($options['approved_only'] && $tags->contains(fn ($tag) => $tag->approved_at === null)) {
            throw new PolicyRejected('This taxonomy accepts approved vocabulary only.');
        }
        if (! $options['assign_parents'] && $tags->isNotEmpty() && $space->query()->where('active', true)->whereIn('parent_id', $tags->modelKeys())->exists()) {
            throw new PolicyRejected('Assignments to parent categories are disabled.');
        }
    }

    public function hierarchy(TagSpace $space): void
    {
        $maximum = Definition::for($space)->options['max_depth'];
        $nodes = $space->query()->get(['id', 'parent_id'])->keyBy('id');
        $depths = [];
        foreach ($nodes as $node) {
            $path = [];
            $cursor = $node;
            while ($cursor && ! isset($depths[$cursor->id])) {
                if (isset($path[$cursor->id])) {
                    throw new HierarchyConflict('Hierarchy cycles are forbidden.');
                }
                $path[$cursor->id] = true;
                if ($cursor->parent_id !== null && ! $nodes->has($cursor->parent_id)) {
                    throw new HierarchyConflict('Parents must belong to the same tenant and taxonomy.');
                }
                $cursor = $nodes->get($cursor->parent_id);
            }
            $depth = $cursor ? $depths[$cursor->id] : 0;
            foreach (array_reverse(array_keys($path)) as $id) {
                $depths[$id] = ++$depth;
                if ($maximum !== null && $depth > $maximum) {
                    throw new HierarchyConflict('Hierarchy exceeds configured maximum depth '.$maximum.'.');
                }
            }
        }
    }

    /** Maintenance may change every context. Validate concrete surviving subjects in bounded batches. */
    public function space(TagSpace $space): void
    {
        $this->hierarchy($space);
        DB::table('tagging_assignments')->whereIn('tag_id', $space->query()->select('id'))
            ->select('taggable_type', 'taggable_id', 'context')->distinct()->orderBy('context')->orderBy('taggable_type')->orderBy('taggable_id')
            ->chunk(200, function ($rows) use ($space) {
                foreach ($rows as $row) {
                    $class = Relation::getMorphedModel($row->taggable_type) ?? $row->taggable_type;
                    if (! is_subclass_of($class, Model::class)) {
                        throw new PolicyRejected('Cannot validate an unresolved subject morph type.');
                    }
                    $subject = (new $class)->newQueryWithoutScopes()->find($row->taggable_id);
                    if (! $subject) {
                        continue;
                    }
                    $selected = new TagSpace($space->tenant, $space->taxonomy, $row->context, true, $space->actorId, $space->operationId);
                    $this->subject($selected, $subject);
                    $violations = $selected->validate($subject);
                    if ($violations) {
                        throw new PolicyViolation($violations);
                    }
                }
            });
    }
}

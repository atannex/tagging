<?php

namespace Atannex\Tagging\Hierarchy;

use Atannex\Tagging\Contracts\HierarchyStrategy;
use Atannex\Tagging\Exceptions\HierarchyConflict;
use Atannex\Tagging\Models\Tag;
use Atannex\Tagging\TagSpace;
use Illuminate\Database\Eloquent\Collection;

final class AdjacencyList implements HierarchyStrategy
{
    /** @return Collection<int, Tag> */
    public function ancestors(TagSpace $space, Tag $tag): Collection
    {
        $nodes = $space->query()->get()->keyBy('id');
        $result = new Collection;
        $seen = [$tag->id => true];
        $cursor = $nodes->get($tag->id)?->parent_id;
        while ($cursor !== null) {
            if (isset($seen[$cursor]) || ! $nodes->has($cursor)) {
                throw new HierarchyConflict('Invalid or cyclic parent path.');
            }
            $seen[$cursor] = true;
            $parent = $nodes[$cursor];
            $result->prepend($parent);
            $cursor = $parent->parent_id;
        }

        return $result;
    }

    /** @return Collection<int, Tag> */
    public function descendants(TagSpace $space, Tag $tag): Collection
    {
        $children = $space->query()->get()->groupBy('parent_id');
        $queue = [$tag->id];
        $seen = [$tag->id => true];
        $result = new Collection;
        for ($i = 0; $i < count($queue); $i++) {
            foreach ($children->get($queue[$i], new Collection) as $child) {
                if (isset($seen[$child->id])) {
                    throw new HierarchyConflict('Hierarchy contains a cycle.');
                }
                $seen[$child->id] = true;
                $queue[] = $child->id;
                $result->push($child);
            }
        }

        return $result;
    }
}

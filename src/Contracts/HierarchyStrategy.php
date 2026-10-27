<?php

namespace Atannex\Tagging\Contracts;

use Atannex\Tagging\Models\Tag;
use Atannex\Tagging\TagSpace;
use Illuminate\Database\Eloquent\Collection;

interface HierarchyStrategy
{
    /** @return Collection<int, Tag> */
    public function ancestors(TagSpace $space, Tag $tag): Collection;

    /** @return Collection<int, Tag> */
    public function descendants(TagSpace $space, Tag $tag): Collection;
}

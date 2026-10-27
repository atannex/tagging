<?php

namespace Atannex\Tagging\Tests\Fixtures;

use Atannex\Tagging\Contracts\SubjectAccess;
use Atannex\Tagging\Exceptions\ScopeViolation;
use Atannex\Tagging\TagSpace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class OwnerAccess implements SubjectAccess
{
    public function assertAccess(TagSpace $space, Model $subject): void
    {
        if ($subject->getAttribute('tenant') !== $space->tenant) {
            throw new ScopeViolation('Subject does not belong to the selected tenant.');
        }
    }

    public function scope(TagSpace $space, Builder $query): Builder
    {
        return $query->where($query->getModel()->qualifyColumn('tenant'), $space->tenant);
    }
}

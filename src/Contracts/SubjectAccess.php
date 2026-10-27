<?php

namespace Atannex\Tagging\Contracts;

use Atannex\Tagging\TagSpace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

interface SubjectAccess
{
    /** Throw ScopeViolation when the selected space cannot access this concrete subject. */
    public function assertAccess(TagSpace $space, Model $subject): void;

    /** Return a fail-closed query restricted to subjects accessible in this space. */
    public function scope(TagSpace $space, Builder $query): Builder;
}

<?php

namespace Atannex\Tagging\Contracts;

use Atannex\Tagging\Models\Assignment;
use Atannex\Tagging\TagSpace;
use Illuminate\Database\Eloquent\Model;

interface SourcePolicy
{
    public function allows(TagSpace $space, Model $subject, ?Assignment $existing, array $incoming, ?array $decision): bool;
}

<?php

namespace Atannex\Tagging\Tenancy;

use Atannex\Tagging\Contracts\SubjectAccess;
use Atannex\Tagging\Exceptions\InvalidConfiguration;
use Atannex\Tagging\Exceptions\ScopeViolation;
use Atannex\Tagging\Models\Tag;
use Atannex\Tagging\TagSpace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class ScopeGuard
{
    public function space(TagSpace $space): void
    {
        if (config('tagging.strict_tenants', false) && ! $space->tenantSelected) {
            throw new ScopeViolation('Select a tenant explicitly with Tagging::for($tenant).');
        }
    }

    private function access(): ?SubjectAccess
    {
        $class = config('tagging.subject_access');
        if (! $class) {
            return null;
        }
        if (! is_string($class) || ! is_a($class, SubjectAccess::class, true)) {
            throw new InvalidConfiguration('subject_access must be a SubjectAccess implementation or bound contract class.');
        }
        $access = app($class);
        if (! $access instanceof SubjectAccess) {
            throw new InvalidConfiguration('subject_access must implement SubjectAccess.');
        }

        return $access;
    }

    public function subject(TagSpace $space, Model $subject, bool $verifyQuery = true): void
    {
        $this->space($space);
        if ($subject->getConnection()->getName() !== (new Tag)->getConnection()->getName()) {
            throw new ScopeViolation('Subject and tagging must use the same default database connection.');
        }
        $access = $this->access();
        $access?->assertAccess($space, $subject);
        if ($access && $verifyQuery && $subject->exists && ! $access->scope($space, $subject->newQuery())->whereKey($subject->getKey())->exists()) {
            throw new ScopeViolation('Subject no longer matches the selected tenant/space.');
        }
    }

    public function query(TagSpace $space, Builder $query): Builder
    {
        $this->space($space);
        if ($query->getModel()->getConnection()->getName() !== (new Tag)->getConnection()->getName()) {
            throw new ScopeViolation('Subject queries must use the tagging database connection.');
        }

        return $this->access()?->scope($space, clone $query) ?? $query;
    }
}

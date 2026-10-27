<?php

namespace Atannex\Tagging\Governance;

use Atannex\Tagging\History\Recorder;
use Atannex\Tagging\Support\SpaceTransactions;
use Atannex\Tagging\TagSpace;
use Atannex\Tagging\Taxonomy\StateValidator;
use Atannex\Tagging\Tenancy\ScopeGuard;
use Closure;
use Illuminate\Database\Eloquent\Model;

/** Validate final state once per composed subject/space mutation, before its transaction commits. */
final class AssignmentGuard
{
    private array $depth = [];

    public function run(TagSpace $space, Model $subject, Closure $callback, bool $lockSubject = true): mixed
    {
        $key = json_encode([$subject->getConnection()->getName(), $subject->getMorphClass(), (string) $subject->getKey(), $space->tenant, $space->taxonomy, $space->context], JSON_THROW_ON_ERROR);

        return app(SpaceTransactions::class)->run($space, function () use ($space, $subject, $callback, $key, $lockSubject) {
            $this->depth[$key] = ($this->depth[$key] ?? 0) + 1;
            try {
                if ($lockSubject) {
                    $locked = $subject->newQuery()->whereKey($subject->getKey())->lockForUpdate()->firstOrFail();
                    app(ScopeGuard::class)->subject($space, $locked, false);
                }
                $recorder = app(Recorder::class);
                $before = $this->depth[$key] === 1 ? $recorder->snapshot($space, $subject) : [];
                $result = $callback();
                if ($this->depth[$key] === 1) {
                    app(StateValidator::class)->subject($space, $subject);
                    $violations = $space->validate($subject);
                    if ($violations) {
                        throw new PolicyViolation($violations);
                    }
                }
                if ($this->depth[$key] === 1) {
                    $recorder->assignments($space, $subject, $before, $recorder->snapshot($space, $subject));
                }

                return $result;
            } finally {
                if (--$this->depth[$key] === 0) {
                    unset($this->depth[$key]);
                }
            }
        });
    }
}

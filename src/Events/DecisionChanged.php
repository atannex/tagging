<?php

namespace Atannex\Tagging\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

final class DecisionChanged implements ShouldDispatchAfterCommit
{
    public function __construct(public readonly string $subjectType, public readonly string $subjectKey, public readonly int $tagId, public readonly string $tenant, public readonly string $taxonomy, public readonly string $context, public readonly ?array $before, public readonly ?array $after) {}
}

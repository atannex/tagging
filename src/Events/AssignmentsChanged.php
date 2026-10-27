<?php

namespace Atannex\Tagging\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

final class AssignmentsChanged implements ShouldDispatchAfterCommit
{
    public function __construct(public readonly string $subjectType, public readonly string $subjectKey, public readonly string $tenant, public readonly string $taxonomy, public readonly string $context, public readonly array $attached, public readonly array $detached, public readonly array $updated, public readonly ?string $actorId = null, public readonly ?string $operationId = null) {}
}

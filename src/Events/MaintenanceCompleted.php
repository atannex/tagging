<?php

namespace Atannex\Tagging\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

final class MaintenanceCompleted implements ShouldDispatchAfterCommit
{
    public function __construct(public readonly string $operation, public readonly ?string $tenant, public readonly ?string $taxonomy, public readonly ?string $context, public readonly int $affected, public readonly ?string $subjectType = null, public readonly ?string $subjectKey = null) {}
}

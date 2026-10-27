<?php

namespace Atannex\Tagging\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

final class VocabularyChanged implements ShouldDispatchAfterCommit
{
    public function __construct(public readonly string $action, public readonly int $tagId, public readonly string $tenant, public readonly string $taxonomy, public readonly ?array $before, public readonly ?array $after, public readonly ?string $actorId = null, public readonly ?string $operationId = null) {}
}

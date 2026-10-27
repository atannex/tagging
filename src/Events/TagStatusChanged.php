<?php

namespace Atannex\Tagging\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

final class TagStatusChanged implements ShouldDispatchAfterCommit
{
    public function __construct(
        public readonly int $tagId,
        public readonly string $tenant,
        public readonly string $taxonomy,
        public readonly bool $wasActive,
        public readonly bool $isActive,
    ) {}
}

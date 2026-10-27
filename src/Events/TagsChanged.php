<?php

namespace Atannex\Tagging\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;

final class TagsChanged implements ShouldDispatchAfterCommit
{
    public function __construct(
        public readonly Model $subject,
        public readonly string $operation,
        public readonly array $tagIds,
        public readonly string $tenant,
        public readonly string $taxonomy,
        public readonly string $context,
    ) {}
}

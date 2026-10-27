<?php

namespace Atannex\Tagging\Contracts;

use Atannex\Tagging\TagSpace;

interface MetadataValidator
{
    /** Throw PolicyRejected for invalid metadata; callbacks must have no external side effects. */
    public function validate(TagSpace $space, array $metadata): void;
}

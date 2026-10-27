<?php

namespace Atannex\Tagging\Tests\Fixtures;

use Atannex\Tagging\Contracts\MetadataValidator;
use Atannex\Tagging\Exceptions\PolicyRejected;
use Atannex\Tagging\TagSpace;

class ColorMetadata implements MetadataValidator
{
    public function validate(TagSpace $space, array $metadata): void
    {
        if (! isset($metadata['color'])) {
            throw new PolicyRejected('A color is required.');
        }
    }
}

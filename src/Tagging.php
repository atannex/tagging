<?php

namespace Atannex\Tagging;

final class Tagging
{
    // Never retain request/tenant state in the container singleton.
    public function for(?string $tenant = null, string $taxonomy = 'tags', string $context = 'default'): TagSpace
    {
        return new TagSpace($tenant ?? '', $taxonomy, $context, $tenant !== null);
    }
}

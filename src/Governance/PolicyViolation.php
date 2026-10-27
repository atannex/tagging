<?php

namespace Atannex\Tagging\Governance;

use DomainException;

final class PolicyViolation extends DomainException
{
    public function __construct(public readonly array $violations)
    {
        parent::__construct(implode(' ', array_column($violations, 'message')));
    }
}

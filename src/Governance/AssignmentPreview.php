<?php

namespace Atannex\Tagging\Governance;

use RuntimeException;

/** Internal successful-preview signal: roll back rather than commit temporary records. */
final class AssignmentPreview extends RuntimeException
{
    public function __construct(public readonly array $report)
    {
        parent::__construct('Assignment preview completed.');
    }
}

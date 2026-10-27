<?php

namespace Atannex\Tagging\Bulk;

use RuntimeException;

/** Internal signal: retain the report while rolling back batch work. */
final class BulkRollback extends RuntimeException
{
    public function __construct(public readonly array $report)
    {
        parent::__construct('Bulk work rolled back.');
    }
}

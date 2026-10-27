<?php

namespace Atannex\Tagging\Bulk;

use RuntimeException;
use Throwable;

/** Distinguish expected mutation failures from exceptions raised during transaction commit. */
final class BulkSubjectFailure extends RuntimeException
{
    public function __construct(public readonly array $details, Throwable $previous)
    {
        parent::__construct($previous->getMessage(), 0, $previous);
    }
}

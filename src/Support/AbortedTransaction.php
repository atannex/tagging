<?php

namespace Atannex\Tagging\Support;

use Illuminate\Database\DeadlockException;
use Throwable;

/** Internal marker associates a whole-transaction abort with its actual retry owner. */
final class AbortedTransaction extends DeadlockException
{
    public function __construct(public readonly object $owner, Throwable $previous)
    {
        parent::__construct($previous->getMessage(), 40001, $previous);
    }
}

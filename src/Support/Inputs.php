<?php

namespace Atannex\Tagging\Support;

use Atannex\Tagging\Exceptions\InvalidConfiguration;
use Atannex\Tagging\Exceptions\PolicyRejected;

final class Inputs
{
    /** Materialize once for retries, but stop at the configured bound rather than exhausting a stream. */
    public static function bounded(iterable $values, string $setting = 'max_tags_per_operation'): array
    {
        $maximum = config('tagging.'.$setting, 100);
        if (! is_int($maximum) || $maximum < 1) {
            throw new InvalidConfiguration($setting.' must be a positive integer.');
        }
        $result = [];
        foreach ($values as $value) {
            if (count($result) === $maximum) {
                throw new PolicyRejected('Input exceeds '.$setting.'.');
            }
            $result[] = $value;
        }

        return $result;
    }
}

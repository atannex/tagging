<?php

namespace Atannex\Tagging\Rules;

use Atannex\Tagging\TagSpace;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use InvalidArgumentException;

/** Validate HTTP-style lists of existing active tag names without database mutations. */
final class TagSelection implements ValidationRule
{
    private readonly int $max;

    public function __construct(
        private readonly TagSpace $space,
        private readonly int $min = 0,
        ?int $max = null,
        private readonly bool $distinct = true,
    ) {
        $configured = config('tagging.max_tags_per_operation', 100);
        $this->max = $max ?? $configured;
        if ($min < 0 || $this->max < $min || $this->max > $configured) {
            throw new InvalidArgumentException('Selection bounds must be non-negative, ordered, and within the operation limit.');
        }
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value) || ! array_is_list($value)) {
            $fail('tagging::validation.selection')->translate();

            return;
        }
        if (count($value) < $this->min) {
            $fail('tagging::validation.min')->translate(['min' => $this->min]);

            return;
        }
        if (count($value) > $this->max) {
            $fail('tagging::validation.max')->translate(['max' => $this->max]);

            return;
        }
        $seen = [];
        foreach ($value as $name) {
            if (! is_string($name) || trim($name) === '' || mb_strlen($name) > 160) {
                $fail('tagging::validation.name')->translate();

                return;
            }
            try {
                $tag = $this->space->find($name);
            } catch (InvalidArgumentException) {
                $fail('tagging::validation.name')->translate();

                return;
            }
            if (! $tag || ! $tag->active) {
                $fail('tagging::validation.unavailable')->translate();

                return;
            }
            if ($this->distinct && isset($seen[$tag->id])) {
                $fail('tagging::validation.distinct')->translate();

                return;
            }
            $seen[$tag->id] = true;
        }
    }
}

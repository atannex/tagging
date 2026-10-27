<?php

namespace Atannex\Tagging\Automation;

use Closure;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

final class Rule
{
    private readonly Closure $predicate;

    public function __construct(
        public readonly string $name,
        callable $predicate,
        public readonly array $tags,
        public readonly float $weight = 1.0,
    ) {
        if (trim($name) === '' || strlen($name) > 100 || ! is_finite($weight) || $weight < 0 || ! $tags || ! array_is_list($tags)) {
            throw new InvalidArgumentException('A rule needs a name, a list of tag names, and a finite non-negative weight.');
        }
        foreach ($tags as $tag) {
            if (! is_string($tag) || trim($tag) === '' || mb_strlen($tag) > 160) {
                throw new InvalidArgumentException('Rule tags must be nonempty names of at most 160 characters.');
            }
        }
        $this->predicate = Closure::fromCallable($predicate);
    }

    public function matches(Model $subject): bool
    {
        return (bool) ($this->predicate)($subject);
    }

    /** Literal, case-insensitive matching, including JSON attributes addressed with dot paths. */
    public static function contains(string $name, array $fields, array $needles, array $tags, string $mode = 'any', float $weight = 1.0): self
    {
        if (! $fields || ! $needles || ! in_array($mode, ['any', 'all'], true)) {
            throw new InvalidArgumentException('Contains rules need fields, search terms, and an any/all mode.');
        }
        foreach ([...$fields, ...$needles] as $value) {
            if (! is_string($value) || trim($value) === '') {
                throw new InvalidArgumentException('Fields and search terms must be nonempty strings.');
            }
        }

        return new self($name, function (Model $subject) use ($fields, $needles, $mode): bool {
            $values = [];
            foreach ($fields as $field) {
                $value = data_get($subject, $field);
                if (is_string($value) || is_numeric($value)) {
                    $values[] = mb_strtolower((string) $value);
                }
            }
            $matched = 0;
            foreach ($needles as $needle) {
                foreach ($values as $value) {
                    if (str_contains($value, mb_strtolower($needle))) {
                        $matched++;
                        break;
                    }
                }
            }

            return $mode === 'all' ? $matched === count($needles) : $matched > 0;
        }, $tags, $weight);
    }
}

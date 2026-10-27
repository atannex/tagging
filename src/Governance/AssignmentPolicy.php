<?php

namespace Atannex\Tagging\Governance;

use Atannex\Tagging\Exceptions\InvalidConfiguration as InvalidArgumentException;
use Atannex\Tagging\TagSpace;
use Illuminate\Database\Eloquent\Model;

/** Declarative application configuration; no request or tenant state is retained. */
final class AssignmentPolicy
{
    public function __construct(
        public readonly int $minTags = 0,
        public readonly ?int $maxTags = null,
        public readonly array $required = [],
        public readonly array $exclusive = [],
        public readonly array $dependencies = [],
    ) {
        if ($minTags < 0 || ($maxTags !== null && $maxTags < $minTags)) {
            throw new InvalidArgumentException('Invalid assignment cardinality limits.');
        }
        $this->names($required);
        foreach ($exclusive as $group) {
            if (! is_array($group) || count($group) < 2) {
                throw new InvalidArgumentException('Exclusive groups need at least two tag names.');
            }
            $this->names($group);
        }
        foreach ($dependencies as $name => $needs) {
            if (! is_string($name) || trim($name) === '' || ! is_array($needs) || ! $needs) {
                throw new InvalidArgumentException('Dependencies must map a tag name to required names.');
            }
            $this->names($needs);
        }
    }

    private function names(array $names): void
    {
        if (! array_is_list($names)) {
            throw new InvalidArgumentException('Policy tag names must be lists.');
        }
        foreach ($names as $name) {
            if (! is_string($name) || trim($name) === '' || mb_strlen($name) > 160) {
                throw new InvalidArgumentException('Policy tag names must contain 1–160 characters.');
            }
        }
    }

    public static function for(TagSpace $space): self
    {
        $policies = config('tagging.policies', []);
        if (! is_array($policies)) {
            throw new InvalidArgumentException('Tagging policies must be an array.');
        }
        $options = $policies[$space->taxonomy] ?? [];
        if (! is_array($options)) {
            throw new InvalidArgumentException('Taxonomy policy must be an array.');
        }
        $contexts = $options['contexts'] ?? [];
        if (! is_array($contexts)) {
            throw new InvalidArgumentException('Policy contexts must be an array.');
        }
        $override = $contexts[$space->context] ?? [];
        if (! is_array($override)) {
            throw new InvalidArgumentException('Context policy must be an array.');
        }
        unset($options['contexts']);
        $options = array_replace($options, $override);
        foreach ($options as $key => $value) {
            if (! in_array($key, ['min_tags', 'max_tags', 'required', 'exclusive', 'dependencies'], true)) {
                throw new InvalidArgumentException('Unknown assignment policy option: '.$key);
            }
        }
        if (array_key_exists('min_tags', $options) && ! is_int($options['min_tags'])) {
            throw new InvalidArgumentException('min_tags must be an integer.');
        }
        if (isset($options['max_tags']) && ! is_int($options['max_tags'])) {
            throw new InvalidArgumentException('max_tags must be an integer or null.');
        }
        foreach (['required', 'exclusive', 'dependencies'] as $key) {
            if (array_key_exists($key, $options) && ! is_array($options[$key])) {
                throw new InvalidArgumentException($key.' must be an array.');
            }
        }

        return new self($options['min_tags'] ?? 0, $options['max_tags'] ?? null, $options['required'] ?? [], $options['exclusive'] ?? [], $options['dependencies'] ?? []);
    }

    public function violations(TagSpace $space, Model $subject): array
    {
        if ($this->minTags === 0 && $this->maxTags === null && ! $this->required && ! $this->exclusive && ! $this->dependencies) {
            return [];
        }
        $ids = $space->tags($subject)->modelKeys();
        $violations = [];
        if (count($ids) < $this->minTags) {
            $violations[] = ['code' => 'min_tags', 'message' => 'At least '.$this->minTags.' active tags are required.'];
        }
        if ($this->maxTags !== null && count($ids) > $this->maxTags) {
            $violations[] = ['code' => 'max_tags', 'message' => 'At most '.$this->maxTags.' active tags are permitted.'];
        }
        $resolved = [];
        $present = function (string $name) use ($space, $ids, &$resolved): bool {
            if (! array_key_exists($name, $resolved)) {
                $resolved[$name] = $space->find($name)?->id;
            }

            return $resolved[$name] !== null && in_array($resolved[$name], $ids, true);
        };
        foreach ($this->required as $name) {
            if (! $present($name)) {
                $violations[] = ['code' => 'required_tag', 'message' => 'Required tag is missing: '.$name, 'tag' => $name];
            }
        }
        foreach ($this->exclusive as $group) {
            $matched = [];
            foreach ($group as $name) {
                if ($present($name)) {
                    $matched[$resolved[$name]] = $name;
                }
            }
            if (count($matched) > 1) {
                $violations[] = ['code' => 'exclusive_tags', 'message' => 'Mutually exclusive tags are assigned.', 'tags' => array_values($matched)];
            }
        }
        foreach ($this->dependencies as $name => $needs) {
            if (! $present($name)) {
                continue;
            }
            foreach ($needs as $need) {
                if (! $present($need)) {
                    $violations[] = ['code' => 'tag_dependency', 'message' => $name.' requires '.$need.'.', 'tag' => $name, 'requires' => $need];
                }
            }
        }

        return $violations;
    }
}

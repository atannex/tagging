<?php

namespace Atannex\Tagging\Taxonomy;

use Atannex\Tagging\Contracts\MetadataValidator;
use Atannex\Tagging\Exceptions\InvalidConfiguration;
use Atannex\Tagging\Exceptions\PolicyRejected;
use Atannex\Tagging\TagSpace;

final class Definition
{
    private const DEFAULTS = [
        'auto_create' => true,
        'approved_only' => false,
        'selection' => 'multiple',
        'max_assignments' => null,
        'max_depth' => null,
        'assign_parents' => true,
        'tag_metadata_validator' => null,
        'assignment_metadata_validator' => null,
        'delete_children' => 'promote',
        'delete_assignments' => 'cascade',
    ];

    public function __construct(public readonly array $options) {}

    public static function for(TagSpace $space): self
    {
        $all = config('tagging.taxonomies', []);
        if (! is_array($all)) {
            throw new InvalidConfiguration('taxonomies must be an array.');
        }
        $base = $all[$space->taxonomy] ?? [];
        if (! is_array($base)) {
            throw new InvalidConfiguration('Taxonomy definitions must be arrays.');
        }
        foreach (['tenants', 'contexts'] as $key) {
            if (isset($base[$key]) && ! is_array($base[$key])) {
                throw new InvalidConfiguration($key.' must be an array.');
            }
        }
        $tenant = $base['tenants'][$space->tenant] ?? [];
        if (! is_array($tenant)) {
            throw new InvalidConfiguration('Tenant definition overrides must be arrays.');
        }
        $options = array_replace($base, $tenant);
        if (isset($options['contexts']) && ! is_array($options['contexts'])) {
            throw new InvalidConfiguration('contexts must be an array.');
        }
        $context = $options['contexts'][$space->context] ?? [];
        if (! is_array($context)) {
            throw new InvalidConfiguration('Context definition overrides must be arrays.');
        }
        foreach (array_keys($context) as $key) {
            if (! in_array($key, ['auto_create', 'approved_only', 'selection', 'max_assignments', 'assign_parents', 'assignment_metadata_validator'], true)) {
                throw new InvalidConfiguration($key.' is vocabulary-wide; configure it at taxonomy or tenant level.');
            }
        }
        unset($options['tenants'], $options['contexts']);
        $options = array_replace(self::DEFAULTS, $options, $context);
        foreach ($options as $key => $value) {
            if (! array_key_exists($key, self::DEFAULTS)) {
                throw new InvalidConfiguration('Unknown taxonomy option: '.$key);
            }
        }
        foreach (['auto_create', 'approved_only', 'assign_parents'] as $key) {
            if (! is_bool($options[$key])) {
                throw new InvalidConfiguration($key.' must be boolean.');
            }
        }
        foreach (['max_depth', 'max_assignments'] as $key) {
            if ($options[$key] !== null && (! is_int($options[$key]) || $options[$key] < ($key === 'max_depth' ? 1 : 0))) {
                throw new InvalidConfiguration($key.' must be a non-negative integer (depth at least one) or null.');
            }
        }
        foreach (['selection' => ['single', 'multiple'], 'delete_children' => ['restrict', 'promote', 'cascade'], 'delete_assignments' => ['restrict', 'cascade']] as $key => $allowed) {
            if (! in_array($options[$key], $allowed, true)) {
                throw new InvalidConfiguration('Invalid '.$key.' option.');
            }
        }
        foreach (['tag_metadata_validator', 'assignment_metadata_validator'] as $key) {
            if ($options[$key] !== null && (! is_string($options[$key]) || ! is_subclass_of($options[$key], MetadataValidator::class))) {
                throw new InvalidConfiguration($key.' must implement MetadataValidator.');
            }
        }

        return new self($options);
    }

    public function metadata(TagSpace $space, mixed $metadata, bool $assignment = false): void
    {
        if ($metadata !== null && ! is_array($metadata)) {
            throw new PolicyRejected('Metadata must be an array or null.');
        }
        $validator = $this->options[$assignment ? 'assignment_metadata_validator' : 'tag_metadata_validator'];
        if ($validator) {
            app($validator)->validate($space, $metadata ?? []);
        }
        $maximum = config('tagging.max_metadata_bytes');
        if ($maximum !== null && (! is_int($maximum) || $maximum < 1)) {
            throw new InvalidConfiguration('max_metadata_bytes must be positive or null.');
        }
        try {
            $json = json_encode($metadata, JSON_THROW_ON_ERROR);
            if ($maximum !== null && strlen($json) > $maximum) {
                throw new PolicyRejected('Metadata exceeds the configured byte limit.');
            }
        } catch (\JsonException $e) {
            throw new PolicyRejected('Metadata must be JSON serializable.', 0, $e);
        }

    }
}

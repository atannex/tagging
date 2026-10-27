<?php

namespace Atannex\Tagging\Support;

use Atannex\Tagging\Contracts\SourcePolicy;
use Atannex\Tagging\Contracts\SubjectAccess;
use Atannex\Tagging\Exceptions\InvalidConfiguration;

final class Settings
{
    public static function validate(): void
    {
        foreach (['strict_tenants', 'review_tracking', 'provenance.enabled', 'maintenance_deltas', 'load_migrations'] as $key) {
            if (! is_bool(config('tagging.'.$key))) {
                throw new InvalidConfiguration($key.' must be boolean.');
            }
        }
        foreach (['transaction_attempts' => 10, 'maintenance_batch_size' => 1000, 'max_tags_per_operation' => PHP_INT_MAX, 'max_bulk_subjects' => PHP_INT_MAX,
            'max_automation_rules' => PHP_INT_MAX, 'max_duplicate_scan_tags' => PHP_INT_MAX, 'max_import_tags' => PHP_INT_MAX,
            'max_import_aliases_per_tag' => PHP_INT_MAX, 'max_import_bytes' => PHP_INT_MAX, 'provenance.retention_days' => PHP_INT_MAX] as $key => $maximum) {
            $value = config('tagging.'.$key);
            if (! is_int($value) || $value < 1 || $value > $maximum) {
                throw new InvalidConfiguration($key.' must be a positive integer'.($maximum === PHP_INT_MAX ? '.' : ' at most '.$maximum.'.'));
            }
        }
        $metadata = config('tagging.max_metadata_bytes');
        if ($metadata !== null && (! is_int($metadata) || $metadata < 1)) {
            throw new InvalidConfiguration('max_metadata_bytes must be positive or null.');
        }
        foreach (['taxonomies', 'policies'] as $key) {
            if (! is_array(config('tagging.'.$key))) {
                throw new InvalidConfiguration($key.' must be an array.');
            }
        }
        foreach (['subject_access' => SubjectAccess::class, 'source_policy' => SourcePolicy::class] as $key => $contract) {
            $class = config('tagging.'.$key);
            if ($class !== null && (! is_string($class) || ! is_a($class, $contract, true))) {
                throw new InvalidConfiguration($key.' must implement '.$contract.'.');
            }
        }
    }
}

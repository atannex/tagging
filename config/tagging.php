<?php

// Every option is described in docs/configuration.md.
return [
    // Migrations load automatically. Set false when you publish and manage copies yourself.
    'load_migrations' => true,

    /*
    | Tenancy
    */
    // Require an explicit tenant for every space (an explicit empty tenant stays valid).
    'strict_tenants' => false,
    // Class implementing Contracts\SubjectAccess, to check that a subject belongs to the tenant.
    'subject_access' => null,

    // Retries of the outer transaction after a deadlock or serialization failure (1–10).
    'transaction_attempts' => 3,

    /*
    | Taxonomy definitions and assignment policies, keyed by exact taxonomy name.
    */
    'taxonomies' => [],
    // Required, exclusive, dependency and min/max rules, with optional context overrides.
    'policies' => [],

    /*
    | Review and provenance
    */
    // Persist review decisions, including rejections that leave no assignment.
    'review_tracking' => false,
    // Class implementing Contracts\SourcePolicy, deciding which source may replace another.
    'source_policy' => null,
    // Record every actual change and decision in the same transaction.
    'provenance' => ['enabled' => false, 'retention_days' => 365],

    /*
    | Maintenance
    */
    // Rows handled per batch by prune and history prune (1–1000).
    'maintenance_batch_size' => 200,
    // Fire per-record AssignmentsChanged events during maintenance.
    'maintenance_deltas' => false,

    /*
    | Input limits
    */
    'max_tags_per_operation' => 100,
    'max_bulk_subjects' => 1000,
    'max_automation_rules' => 100,
    'max_duplicate_scan_tags' => 300,
    // Bytes of encoded tag metadata; null means unlimited.
    'max_metadata_bytes' => null,
    'max_import_tags' => 5000,
    'max_import_aliases_per_tag' => 100,
    // Bytes of an import document or file (10 MiB).
    'max_import_bytes' => 10485760,
];

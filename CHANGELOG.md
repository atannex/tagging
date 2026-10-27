# Changelog

All notable changes to `atannex/tagging` are documented here. The project follows [Semantic Versioning](https://semver.org).

## v1.0.0 (2026-10-27)

First public release.

### Tag spaces and assignments

- Immutable `TagSpace` (tenant + taxonomy + context) with explicit tenant selection that survives queues and workers; optional strict tenants and `SubjectAccess` ownership checks.
- `HasTags` model helpers: attach, sync, detach, toggle, replace, all/any/none checks, weighted and expiring assignments, renewal of expired assignments.
- Aliases, renaming with old-name aliases, translations, hierarchy with move/ancestors/descendants and cycle detection.
- Atomic assignment copying with attribute preservation and optional overwrite.

### Querying

- Assignment-aware matching with source labels, weight ranges, expiration filters, and subtree/ancestor matching.
- Faceted counts and weights over distinct application-filtered subjects, independent of pagination.
- Similar-content and related-tag discovery, windowed usage ranking, popular tags and prefix suggestions.
- Batched eager loading through `LoadedAssignments` and `AssignmentFilter`.

### Governance and review

- Declarative taxonomy definitions and policies (required, exclusive, dependencies, min/max, single selection) with tenant and context overrides, validated on the final state of every write.
- Structured `PolicyViolation` errors, sync previews that roll back fully, and read-only policy validation.
- Vocabulary approval, review decisions, source policies, and optional transactional provenance.

### Bulk work and automation

- Bounded bulk attach/sync/detach over filtered queries with per-subject reports, atomic or independent commits, and rolled-back dry runs.
- Rule-based auto-tagging with literal and nested JSON field matching, custom predicates, previews, and source-owned synchronization.
- `TagSelection` validation rule with publishable translations.

### Vocabulary management

- Versioned export and atomic, size-bounded import with previews and conflict policies.
- Merge, delete with child/assignment strategies, activation/deactivation with subtree cascades.
- Vocabulary health reports and Unicode-aware duplicate suggestions.

### Operations

- Artisan commands: `tagging:import`, `tagging:export`, `tagging:health`, `tagging:prune`, `tagging:history-prune`.
- Domain events with deltas and an optional `AuditSink`.
- Deadlock/serialization retries, per-namespace locking, and exact binary identity keys on MySQL.
- Tested on SQLite, MySQL 8.4 and PostgreSQL 16, including independent-process race tests.

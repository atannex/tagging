# Upgrading from a pre-release checkout

New installations of v1.0.0 can skip this guide: `php artisan migrate` creates the complete schema.

It is for applications that ran an early development checkout of the package (the original `2026_10_27_000001_create_tagging_tables.php` schema only). v1.0.0 hardens that engine. Most additions are opt-in, but two deliberate identity/import hardenings need application review.

## Preserved contract

Namespace, facade, model helpers, existing query parameters, table identities, tag/assignment composite identity, empty tenant/tags/default context, any/all/none input semantics, aliases, translations, inactive/expired reads, heterogeneous subject keys, morph maps, soft deletion, non-negative unbounded weights, and the legacy `TagsChanged` payload/no-op behavior remain. New optional parameters are appended. `tagAssignments` remains a raw MorphMany-compatible relationship; its SQL key comparison is made portable without adding filters.

Default source replacement remains permissive. Strict tenants, ownership restrictions, definition rules, review tracking/protection, persisted history, and per-record maintenance notifications are disabled unless configured.

## Changes requiring application migration

1. **Explicit import destination.** Legacy `Tagging::for()->import($document)` and `new TagSpace()->import(...)` now throw `ImportFailure`. Migrate to `Tagging::for('')->import(...)` if the empty tenant is intended, or pass a trusted nonempty tenant. CLI imports require `--tenant`, including `--tenant=`. This resolves the conflict between accepting an implicit destination and requiring explicit tenant selection for vocabulary transfer.
2. **Exact MySQL identities.** Previous MySQL defaults could compare case/accents/trailing spaces differently from PHP, SQLite, and PostgreSQL. The new binary NO PAD collation makes these keys exact. Review application selectors against the actual stored key strings. If your application intentionally treated keys case-insensitively, canonicalize trusted keys explicitly at its boundary or perform a reviewed application-owned data migration before upgrading. The package does not silently rewrite tenant keys.
3. Imported arrays now receive the encoded document-size limit already used by the CLI. Invalid tag-state/translation/metadata values are rejected explicitly. Ordinary valid documented arrays remain supported. Metadata byte limits remain opt-in (`null` by default).

## Migrations

The original `2026_10_27_000001_create_tagging_tables.php` is byte-for-byte unchanged. Apply these new migrations:

- `2026_10_28_000001_harden_tagging_engine.php`: persistent namespace locks; nullable vocabulary approval; review decisions; optional transactional provenance; current-assignment/candidate/history indexes.
- `2026_10_28_000003_index_tagging_parents.php`: adds a parent lookup index where no leading parent index exists (MySQL commonly already supplies an FK index).
- `2026_10_28_000002_enforce_binary_scope_keys.php`: MySQL `utf8mb4_0900_bin` identity columns. SQLite/PostgreSQL need no collation change.

Back up first and schedule appropriate DDL maintenance for existing MySQL tables: index/column changes can rebuild tables and take locks. MySQL DDL is not an atomic multi-table transaction. Stop application writes and do not run the new engine until all migrations finish. If an ALTER fails, inspect/fix the cause and rerun the migration before restarting writers; the migration is repeatable over already-converted columns. MySQL 8.4 is verified; MariaDB/older MySQL collations are not advertised as supported by this migration.

Test the upgrade on a copy, run `tagging:health --tenant=...`, then enable optional settings incrementally. Published-migration users must publish/copy the new files as well, preserving their ordering and avoiding duplicate automatic loads. Do not delete old migration records or rewrite the original schema migration.

## Rollback limits

The hardening migration can remove new tables/approval/indexes, which necessarily removes stored decisions/history. Export/backup them before a downgrade if retention is required.

The MySQL collation down migration preflights identity collisions **before any ALTER**. If new exact identities would collapse under the old collation, it raises `VocabularyCollision` and changes nothing. It does not merge/delete tenant data to make a rollback succeed. Reconcile keys in an audited application migration or restore a reviewed backup; only then roll schema back and switch back to the earlier code. Keep code and schema migration ordering coordinated.

## Operational follow-up

- Keep morph maps stable or add explicit historical aliases before class renames.
- Configure actor IDs explicitly; operation IDs are correlation, not global idempotency tokens.
- Move irreversible model-observer effects to after-commit integrations before relying on automatic transaction retries.
- When enabling ownership, audit existing legitimate shared/cross-tenant subjects first; an overly narrow hook will intentionally reject them.
- When enabling constraints, validate current assignments; time-based expiration and explicit administrative deactivation can later invalidate required/minimum policies.

The factory/constructor uses a nullable omission sentinel internally to distinguish omitted tenant arguments from an explicit empty string, including named-argument calls. The exposed `tenant` property and actual default space remain the readonly string `''`. Existing valid string calls remain compatible. Serialized spaces from the pre-release checkout (envelope version 1) restore without uninitialized new properties; before enabling strict mode, drain/re-envelope old empty-tenant jobs because their original explicit-selection intent was not recorded.

# Transactions and concurrency

All supported package mutations acquire a persistent lock row for the exact tenant/taxonomy before assignment/subject or hierarchy changes. `tagging_space_locks` uses a SHA-256 key over the unambiguous JSON pair. Its row is created with a non-erroring conflict insert and selected `FOR UPDATE`. This works when no tags exist yet. It is intentionally coarse: writers in one namespace serialize, including different assignment contexts.

The namespace lock precedes subject locks. Copies lock both subjects in a stable table/key order. Hierarchy edits and vocabulary maintenance use the same namespace boundary, so supported assignment writes cannot interleave with a partial merge or tree edit. Unique indexes remain the final identity constraint. Name lookups inside transactions use current locking reads; PostgreSQL errors are allowed to escape to the savepoint/transaction boundary before recovery.

Canonical creation returns the existing tag after a recognized identity race; aliases cannot claim a name owned by another tag. Only recognized unique-index conflicts are mapped/recovered. Unrelated SQL errors propagate. Names resolve transferred aliases after merge; a stale deleted `Tag` object is rejected rather than silently recreated.

## Retries

`transaction_attempts` defaults to three. The engine retries recognized SQLSTATE `40001`/`40P01`, MySQL deadlock/lock-timeout codes, and SQLite busy/locked codes only when it owns the outer transaction. It uses Laravel's public begin/commit/rollback APIs and clears failed-COMMIT transaction callbacks before replay. Classification uses database exception codes rather than message substring matching. Typed domain errors are never retried, even if their messages contain SQL concurrency terminology. It does not blindly retry metadata errors, policy rejection, unique violations on unrelated indexes, or arbitrary exceptions.

Nested package transactions use savepoints and one attempt. A nested failure that aborts the enclosing transaction must be handled by retrying that **whole application transaction**, not by retrying one failed statement. In particular, PostgreSQL must roll back an errored savepoint/transaction before another query can succeed. Tests coordinate real MySQL/PostgreSQL sessions to exercise this boundary, including deadlock and PostgreSQL SERIALIZABLE retries.

```php
$space->transaction($article, function () use ($space, $article) {
    $space->detach($article, ['Draft']);
    $space->attach($article, ['Published']);
});
```

Governance checks the final state at the outermost subject/space boundary; valid replacements do not fail due to transient intermediate states. Do not manually commit/roll back or swallow a transaction-aborting concurrency exception inside a tagging callback. An application `DB::transaction()` alone does not combine separate governance operations into one final-state check. Use `TagSpace::transaction()` for that composition. Cross-space application work should acquire spaces in a consistent order and provide an application-level retry boundary when needed.

A retried callback may execute more than once. Metadata/source/access hooks, model observers, and automation predicates must not send mail, call remote APIs, charge accounts, or perform other irreversible effects inside these transactions. Use after-commit listeners/jobs for integration. Public package notifications defer until the enclosing commit; rolled-back attempts do not emit committed notifications. After-commit failure cannot undo a database commit, and no durable outbox delivery is promised.

## Connection and direct-write limits

Tagging tables and subjects must use the same configured default connection name. The package does not coordinate commits across connections, even when two connections point at the same physical database. PostgreSQL, MySQL, and SQLite are exercised; other database families are not claimed as tested.

Raw SQL and direct model changes bypass namespace locks, taxonomy/source/access rules, events, and provenance. They can introduce corruption or races the package cannot prevent. Imports, merges, and moves validate surviving concrete subjects in bounded batches. Missing morph mappings fail validation; orphan subject rows are reported/handled as documented maintenance data, not authorized as business subjects.

Soft deletion retains assignments. To make deletion of the application model and its assignment cleanup one atomic business action, enclose model deletion in the application's transaction: Eloquent's `deleting` hook runs before the model's own SQL delete. Force/ordinary deletion cleanup emits a scoped summary and optional per-record deltas; bulk model SQL deletion still bypasses model hooks.

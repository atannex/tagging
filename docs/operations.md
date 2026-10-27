# Diagnostics and maintenance

```sh
php artisan tagging:health --tenant=tenant-42 --taxonomy=topics --context=editorial
php artisan tagging:health --tenant=tenant-42 --taxonomy=topics --json
```

Health is read-only. It checks settings and taxonomy configuration, required tables/columns/indexes, MySQL key collation, canonical name entries, namespace/parent references, hierarchy cycles, and safely resolvable morph mappings. Structural checks cover the entire tenant/taxonomy; assignment hygiene counts use the requested context.

Stable issue codes include `invalid_configuration`, `missing_migration`, `missing_column`, `missing_index`, `invalid_scope_collation`, `missing_canonical_name`, `name_scope_mismatch`, `invalid_parent_scope`, `hierarchy_cycle`, and `unresolved_morph`. A missing schema produces an actionable report rather than attempting repairs. Names are compared against required index definitions by their installed names; review manually renamed indexes rather than blindly recreating them.

Unresolvable stored morph types are reported without guessing a replacement class or querying unknown subject tables. Health does not scan every subject table to prove that every key exists, does not reconstruct erased history, and does not repair records. It loads vocabulary/name entries into memory; run it administratively on appropriately sized namespaces and avoid concurrent vocabulary maintenance when investigating a suspected graph problem.

The command exits unsuccessfully for structural/configuration issues. Unused tags, expired assignments, and live assignments to inactive tags are informational hygiene counts, not structural failures. Output includes scope/identifiers and configuration/index messages, not credentials or metadata values. Application-defined validator messages should also avoid sensitive data.

## Expiration pruning

Existing global behavior remains available:

```sh
php artisan tagging:prune --dry-run
php artisan tagging:prune
```

Or select tenant/taxonomy/context independently:

```sh
php artisan tagging:prune --tenant=tenant-42 --taxonomy=topics --context=editorial --dry-run
php artisan tagging:prune --tenant=tenant-42 --taxonomy=topics --context=editorial
```

An omitted option means all values. An explicit empty `--tenant=` selects only the default tenant. Pruning rechecks expiration under the namespace lock before deleting each bounded group; a renewed assignment is not blindly removed from a stale ID list. It removes assignments expiring at or before now and retains vocabulary and decisions.

Each maintenance group commits independently unless enclosed by an application transaction. Summaries report actual affected counts after completion. A database failure can stop a multi-group operation after earlier groups committed; it is not reported as a complete success. Re-running the same scoped operation is safe for remaining eligible rows. Use application orchestration if you require one transaction over an entire maintenance run.

`maintenance_batch_size` defaults to 200, capped at 1,000. `maintenance_deltas=true` enables per-record assignment notifications in addition to summaries. Enabled provenance is transactional regardless of that notification option. Retention uses the separate `tagging:history-prune` command described in [provenance](provenance.md).

## Stable exception families

Under `Atannex\Tagging\Exceptions`:

| Exception | Meaning |
| --- | --- |
| `InvalidConfiguration` | Invalid policy, hook, or setting. |
| `ScopeViolation` | Implicit strict selection, inaccessible subject, cross-space tag, or connection mismatch. |
| `HierarchyConflict` | Cross-space/invalid/cyclic/deep tree or restricted child operation. |
| `VocabularyCollision` | Namespace conflict, protected canonical alias, or unsafe identity-collapsing downgrade. |
| `PolicyRejected` | Definition, metadata, source, or review rule rejects mutation. |
| `ImportFailure` | Invalid version/document/plan or import policy failure. |

These extend `InvalidArgumentException` for compatibility with existing application error mapping. Legacy `Governance\PolicyViolation` remains a `DomainException` with structured violations. Bulk input-size bounds may raise `LengthException` as documented. Missing models and unrelated database/after-commit errors retain their framework exceptions; do not classify every SQL error as an expected race.

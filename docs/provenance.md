# Source precedence, review, and provenance

An assignment's `source` is mutable data, not part of its unique identity. By default, direct attach/sync overwrite only supplied fields, exactly as before. Existing automation has its own documented source-ownership preservation behavior; enabling a new source policy is not required for that legacy behavior.

## Optional replacement policy

Configure a class implementing `Contracts\SourcePolicy`:

```php
'source_policy' => Atannex\Tagging\History\ReviewedSourcePolicy::class,
'review_tracking' => true,
'provenance' => ['enabled' => true, 'retention_days' => 365],
```

The built-in `ReviewedSourcePolicy` allows manual incoming writes, rejects non-manual writes for explicit rejections, and protects approved manual assignments from a non-manual overwrite. Manual means exact source `manual`; an application needing multiple human source labels should implement the contract. The hook receives space, concrete subject, optional existing Assignment, incoming attributes, and optional stored decision. False raises `PolicyRejected` and rolls back the mutation.

Source/metadata validation is reused during assignment transfer in merge. Existing target values win identity conflicts, so no incoming overwrite occurs for such a conflict. Missing subjects cannot satisfy an enabled source/access hook; maintenance rejects that transfer rather than guessing permission.

## Explicit review decisions

```php
$review = $topics->withOperation(actorId: 'editor-17', operationId: 'review-2026-10-27-1');
$review->decide($article, ['Sensitive'], 'rejected');
$review->decide($article, ['Engineering'], 'approved');
```

Review requires existing tags and `review_tracking=true`. A rejection is persisted in `tagging_decisions` even without an assignment and removes an existing assignment atomically. Approval records a decision; it does not automatically attach a tag. Repeating the same decision is a no-op. Alias inputs are deduplicated by canonical tag ID. Review identity is the same tag/type/key/context tuple, not an extra source dimension.

Rejected/manual reattachment is possible when explicitly done by a permitted manual source; the rejection still blocks later automation until an explicit approval changes it. Required-tag/minimum policies may reject removal by a review decision; replace or otherwise reconcile the assignment set in one composed transaction. Vocabulary approval via `approve($tag)` is a separate global vocabulary attribute.

## Transactional history

When `provenance.enabled=true`, actual assignment deltas, vocabulary lifecycle changes, and decisions are inserted into `tagging_changes` in the same transaction as their changes. Records contain scope, kind, tag/subject identifiers, before/after JSON, timestamp, and nullable explicit actor/operation IDs. They intentionally have no destructive foreign keys to mutable tags or subjects.

Actor and operation identifiers are never inferred from HTTP/Auth globals. `withOperation()` returns a new immutable space and can be used by HTTP controllers, scheduled work, or queue jobs. An operation ID is correlation data, **not a global exactly-once/idempotency key**. Repeating an identical attach/update/decision does not create a second change record; changing data again is another change even if the caller reuses its correlation ID. Legacy no-op `TagsChanged` events still occur where documented.

History is not a reconstruction of every application event. Direct SQL/model writes bypass it. It is transactional storage, unlike after-commit audit delivery. Payloads can include application metadata: restrict access and choose an appropriate application redaction/storage policy.

## Merge, cleanup, and retention

Merge keeps target assignment values and review decisions on conflicts. Other source assignments/decisions transfer; source decisions are removed with explicit review-change records. Source canonical names and aliases become target aliases, and child reparenting is recorded. Source history remains as tombstones referencing its former ID.

Explicit vocabulary deletion follows configured child/assignment policies. Decisions for deleted tags are removed and recorded; history survives. Subject force/ordinary deletion removes assignments and decisions with history and a cleanup summary. Soft deletion retains both. Pruning expired assignments retains review decisions so a negative decision does not disappear simply because an assignment expired.

```sh
php artisan tagging:history-prune --days=365 --tenant=tenant-42 --dry-run
php artisan tagging:history-prune --days=365 --tenant=tenant-42
```

Retention deletes history in bounded ID batches; it does not delete decisions. No scheduled retention job runs unless the application schedules the command. Scope the command explicitly when applying a per-tenant retention policy. See [event coverage](events.md) for notification versus persistence details.

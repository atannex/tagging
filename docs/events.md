# Events and audit integration

Every public notification implements `ShouldDispatchAfterCommit`. It is delivered after the outermost successful database transaction, not after an inner savepoint. A rollback, failed import preview, or retried attempt does not produce a committed notification.

`TagsChanged` retains its original model, operation, tag IDs, tenant, taxonomy, and context payload. Attach/sync IDs are resolved requested IDs; detach IDs are removed assignments' tags. Its legacy no-op behavior is preserved. New `AssignmentsChanged` events report actual attached, detached, and updated rows with normalized before/after weight, source, metadata, and expiration values. They omit no-ops and coalesce nested subject/space operations at their governance boundary.

`VocabularyChanged` has an action, tag ID, tenant/taxonomy, before/after data, and optional explicit actor/operation IDs. Actions cover creation, rename, alias addition/removal, move, approval, activation/deactivation, import, merge, and deletion. Implicit child promotion/reparenting is reported as movement. Merge includes transferred aliases and target identity. Existing `TagStatusChanged` notifications remain available for explicit activate/deactivate APIs.

`DecisionChanged` reports subject identity, tag/space, and nullable before/after decision data; null after means removal. `MaintenanceCompleted` reports operation, optional selected scope, affected count, and optional cleanup subject identity. It is a summary, not evidence of a durable external integration.

## Coverage matrix

| Supported action | Legacy `TagsChanged` | New notifications | Transactional provenance when enabled |
| --- | --- | --- | --- |
| Attach/sync/detach, including legacy no-op | Yes | Assignment delta only for actual change | Actual changes only |
| Toggle/replace/composed transaction | Underlying legacy operations | Coalesced final subject/space delta | Final assignment changes |
| Copy | Destination attach operations | Final destination delta | Destination changes |
| Automation | Underlying attach/detach operations | Final subject/space delta | Final assignment changes |
| Bulk | Per-subject legacy operations | Per-subject final deltas | Successful retained changes |
| Create/rename/alias/move/approve | No | Vocabulary lifecycle | Yes |
| Explicit activate/deactivate | No | Vocabulary lifecycle + legacy status event | Yes |
| Merge | No, preserving original coverage | Merge, alias/child changes, assignment/decision deltas | Yes |
| Explicit vocabulary delete | No | Delete/child changes; assignment deltas if maintenance integration enabled; decision removals | Yes |
| Import | No | Per-tag lifecycle; no committed events in dry run | Yes |
| Expiration pruning | No | Scoped summary; optional per-record deltas | Yes |
| Subject cleanup | No | Subject summary; optional per-record deltas; decision removals | Yes |
| History retention | No | Scoped summary | Does not recursively audit its own deletion |
| Direct model updates/raw SQL/bulk subject SQL deletion | No package guarantee | Outside coverage | Outside coverage |

`maintenance_deltas=true` enables per-assignment maintenance events; it does not affect whether enabled provenance is stored. Normal time passing past an expiration is a read-time state change, not a mutation event. Schedule maintenance if removal notifications are needed.

## Audit sink

```php
use Atannex\Tagging\Contracts\AuditSink;

final class ApplicationTagAudit implements AuditSink
{
    public function record(object $event): void
    {
        logger()->info('Tagging change committed', ['event' => $event::class]);
    }
}

// In your application service provider:
$this->app->bind(AuditSink::class, ApplicationTagAudit::class);
```

The package invokes a bound sink for assignment, vocabulary, decision, and maintenance notifications. Consumers can also register ordinary Laravel event listeners. The example logs event class only to avoid leaking metadata; an application needing structured storage should explicitly select permitted fields.

A sink/listener failure occurs after the data commit and propagates. It cannot undo the commit, and external delivery can fail or be duplicated by application retries. For durable delivery, build an application outbox inside its own appropriate transaction; the package does not claim an outbox or guaranteed exactly-once external delivery. Keep external effects out of retryable mutation callbacks and ordinary model observers.

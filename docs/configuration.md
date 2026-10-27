# Configuration and taxonomy definitions

The defaults in [config/tagging.php](../config/tagging.php) preserve permissive assignment behavior. New schema tables exist after migration, but review tracking and provenance remain disabled until enabled.

| Setting | Default | Purpose |
| --- | --- | --- |
| `load_migrations` | `true` | Load package migration files automatically. |
| `strict_tenants` | `false` | Require explicit tenant selection. An explicit empty tenant remains valid. |
| `subject_access` | `null` | Application implementation of `Contracts\SubjectAccess`. |
| `transaction_attempts` | `3` | Outer transaction concurrency retries; valid range 1–10. |
| `taxonomies` | `[]` | Definitions below, indexed by exact taxonomy key. |
| `policies` | `[]` | Existing required/exclusive/dependency/min/max policies. |
| `review_tracking` | `false` | Persist review decisions, including rejections without assignments. |
| `source_policy` | `null` | Optional `Contracts\SourcePolicy` implementation. |
| `provenance.enabled` | `false` | Persist actual changes and decisions transactionally. |
| `provenance.retention_days` | `365` | Default history-prune retention. Decisions are separate. |
| `maintenance_batch_size` | `200` | Bounded maintenance batch size, 1–1000. |
| `maintenance_deltas` | `false` | Per-record assignment notifications during maintenance. |
| `max_tags_per_operation` | `100` | Bounds assignment inputs, including replayable iterables. |
| `max_bulk_subjects` | `1000` | Maximum snapshotted subjects in a bulk report. |
| `max_automation_rules` | `100` | Bounds rule evaluation inputs. |
| `max_duplicate_scan_tags` | `300` | Bounds pairwise duplicate suggestions. |
| `max_import_tags` | `5000` | Bounds a single atomic vocabulary document. |
| `max_import_aliases_per_tag` | `100` | Bounds imported aliases. |
| `max_import_bytes` | `10485760` | Maximum encoded document/file size. |
| `max_metadata_bytes` | `null` | Optional metadata size limit; null preserves legacy permissiveness. |

## Definitions

```php
// config/tagging.php
return [
    'taxonomies' => [
        'status' => [
            'auto_create' => false,
            'approved_only' => true,
            'selection' => 'single',
            'max_assignments' => 1,
            'max_depth' => 3,
            'assign_parents' => false,
            'delete_children' => 'restrict',
            'delete_assignments' => 'restrict',
            'tenants' => [
                'tenant-42' => [
                    'max_depth' => 5,
                    'contexts' => ['internal' => ['approved_only' => false]],
                ],
            ],
            'contexts' => ['suggested' => ['selection' => 'multiple', 'max_assignments' => 5]],
        ],
    ],
];
```

Definitions merge defaults, taxonomy options, tenant overrides, then assignment-context overrides. Lists/options are replaced rather than recursively concatenated. Contexts may override `auto_create`, `approved_only`, `selection`, `max_assignments`, `assign_parents`, and `assignment_metadata_validator`. Hierarchy depth, tag metadata, and deletion are vocabulary-wide: configure these at taxonomy/tenant level. Conflicting structural context options raise `InvalidConfiguration`.

`auto_create=false` blocks implicit creation by assignments and automation. Explicit administrative `create()` and import still create definitions. `approved_only=true` requires `approve($tag)` before assignment; it does not approve newly created labels automatically. Approval is vocabulary-wide and distinct from a subject's review decision.

`single` limits a subject/context to one current active tag. `max_assignments` is nullable; when both apply, the stricter limit wins. Inactive tags and expired assignments do not consume the limit. Weight zero still consumes a slot. Inputs and resulting state are validated inside the namespace/subject transaction, so two supported concurrent writes cannot each claim the final slot.

Hierarchy root depth is one; depth validation includes inactive nodes and the entire moved subtree. `assign_parents=false` forbids assigning active tags that have active children. Moving a child, merging, or importing a graph revalidates affected subjects and contexts before commit. A failed policy rolls back the complete mutation.

Existing `policies` continue to support `min_tags`, `max_tags`, `required`, `exclusive`, and `dependencies`, with their existing context overrides. They combine with definitions, not replace them. `validate($subject)` returns both legacy policy violations and a `taxonomy_policy` violation when definition constraints fail. Mutation-time definition failures throw `PolicyRejected`; legacy policy failures retain `Governance\PolicyViolation` and its `violations` payload.

## Metadata contracts

Tag and assignment metadata have separate validators:

```php
use Atannex\Tagging\Contracts\MetadataValidator;
use Atannex\Tagging\Exceptions\PolicyRejected;
use Atannex\Tagging\TagSpace;

final class RequiresColor implements MetadataValidator
{
    public function validate(TagSpace $space, array $metadata): void
    {
        if (!isset($metadata['color']) || !is_string($metadata['color'])) {
            throw new PolicyRejected('A string color is required.');
        }
    }
}
```

Set `tag_metadata_validator` or `assignment_metadata_validator` to the implementation class. Validators receive explicit space, must not perform external side effects, and may run again after a concurrency retry. Metadata must be an array or null and JSON serializable. The optional byte limit is independent of custom schema validation. If a tag schema requires fields, pre-create those tags with valid metadata instead of relying on empty automatic creation.

## Extension contracts

- `SubjectAccess`: concrete-subject checks and fail-closed candidate query restriction.
- `SourcePolicy`: incoming assignment overwrite decisions, with optional persisted review data.
- `MetadataValidator`: tag or assignment schema validation.
- `HierarchyStrategy`: ancestor/descendant reads; bind to replace adjacency traversal while preserving `parent_id` as the authoritative structural invariant.
- `AuditSink`: after-commit notification integration, not transactional storage or guaranteed delivery.

Bindings belong in application providers. Domain services never resolve an actor from Auth or HTTP globals.

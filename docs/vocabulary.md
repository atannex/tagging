# Vocabulary lifecycle and portable documents

Use `TagSpace` methods rather than direct model updates for namespace/tree changes.

- `create($name, $attributes)` returns an existing canonical tag when the name/alias already resolves. Existing attributes are not overwritten.
- `alias($tag, $name)`, `aliases($tag)`, and `removeAlias($tag, $name)` maintain the shared unique namespace. Canonical names cannot be removed as aliases.
- `rename($tag, $name, keepOldName: true)` preserves old canonical names as aliases by default. A tag's own alias may be promoted. Passing false removes only the immediately previous canonical name.
- `move($tag, $parent)` rejects cross-space parents, cycles, invalid depth, and resulting taxonomy/assignment violations. Null means root.
- `approve($tag)` sets vocabulary approval. It is not a subject review decision.
- `activate()` / `deactivate()` retain assignments and aliases; optional `descendants:true` changes the subtree explicitly. Expirations are not extended.
- `merge($source, $target)` transfers aliases, assignment identities, and review decisions, reparents direct children, deletes the source, and retains target values on identity conflicts. It rejects merging into a descendant and validates the resulting namespace. Inactive targets remain inactive; raw assignments survive but are hidden by active reads.
- `delete($tag, children: ..., assignments: ...)` uses configurable policies. Children may be `restrict`, `promote` to the removed tag's parent, or `cascade`. Assignments may be `restrict` or `cascade`. Defaults are promote/cascade. Restriction considers stored assignments, including expired ones. Decisions for deleted tags are removed; enabled history remains.

Promotion and merge reparenting emit movement notifications. Activation/deactivation/deletion can make surviving subjects fail minimum/required policies; these explicit administrative maintenance actions do not synthesize replacement labels. Revalidate at policy-sensitive business transitions. Raw model updates and SQL bypass all guarantees and coverage; see [transactions](transactions.md).

## Versioned export

```php
$v1 = $source->export();  // Existing version-1 shape, preserved.
$v2 = $source->export(2); // Adds an explicit approved boolean per tag.
```

Both documents contain `version` and a `tags` list. Each row has canonical `name` and `slug`, nullable parent canonical slug, normalized aliases, translations, metadata, and active state. Version 2 adds `approved`. Database IDs, tenant identifiers, subjects, assignments, provenance, and review decisions are not exported/imported. Their transfer is not supported by this format and must not be assumed.

```sh
php artisan tagging:export --tenant=tenant-42 --taxonomy=topics --format-version=2 > topics.json
```

The CLI flag is `--format-version`; `--version` is reserved by Symfony Console.

## Explicit destination and conflict policies

```php
$destination = Tagging::for('tenant-99', 'topics', 'editorial');
$preview = $destination->import($v2, dryRun: true, conflict: 'update');
$result = $destination->import($v2, conflict: 'update');
```

An explicitly selected destination is mandatory, including `Tagging::for('')` for the global tenant. Exported/requested tenant identifiers are never an authorization source. Authorize vocabulary administration before selecting the destination.

| Conflict policy | Existing canonical row |
| --- | --- |
| `update` (default) | Replace supplied attributes and parent, add aliases, retain extra existing aliases. |
| `keep` | Leave the whole existing row and its aliases/parent unchanged. |
| `fail` | Reject the complete document if a canonical row already exists. |

Names owned by a different canonical tag or an alias collision reject the document under every policy. Repeatable default imports update existing rows without creating duplicate IDs. Reports retain the existing `created`, `updated`, and `dry_run` fields; non-default policies also report `kept`. Updated counts mean matched canonical rows, not necessarily changed values.

```sh
php artisan tagging:import topics.json --tenant=tenant-99 --taxonomy=topics --conflict=update --dry-run
php artisan tagging:import topics.json --tenant=tenant-99 --taxonomy=topics --conflict=update
```

## Validation and transaction boundary

The engine validates version, list structure, encoded bytes, row/alias counts, bounded labels, canonical normalization, duplicate names, intra-document collisions, parent references, cycles, metadata schemas, and applicable taxonomy rules. Unsupported versions, malformed documents, and rejected plans raise `ImportFailure` with the original cause when applicable. SQL/system errors are not disguised as invalid-document errors.

The graph is applied as a plan and validated once before commit. A valid rearrangement is not rejected because of a temporary intermediate parent graph. Alias/reference conflicts with existing data are checked inside the namespace lock. Existing assignments across contexts are revalidated where vocabulary changes affect them. Version 1 leaves existing approval state unchanged; new version-1 tags are unapproved. Version 2 explicitly transfers approval state and can therefore be rejected if it would violate approved-only assignments.

Imports use one all-or-nothing transaction per bounded document; there is no partial-batch import mode or completed-batch claim. The default encoded limit is 10 MiB, 5,000 tags, and 100 aliases per row. JSON decoding and export documents are in-memory, not unbounded streaming. Partition very large vocabularies deliberately and account for cross-document references yourself. Assignment validation and maintenance iterate affected subjects in batches.

A dry run executes the same plan in a rolled-back transaction, suppressing temporary model observers and committed notifications. Sequences may advance. It needs a writable connection; it is not a read-only query or authorization/approval token. Real execution rechecks current state.

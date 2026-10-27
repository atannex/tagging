# Atannex Tagging

A contextual vocabulary engine for Laravel. Use one system for post tags, product labels, hierarchical categories, skills, interests, moderation labels, editorial topics, and machine-generated classifications.

The central concept is a **tag space**: a tenant owns a vocabulary within a taxonomy; a context identifies how that vocabulary is applied to a model. A product can have editorial topics and SEO topics without either assignment replacing the other. Tenant state is explicit and immutable, including in queues and long-running workers.

PHP 8.2+, Laravel 11–13. MIT licensed. No dependency on other Atannex packages.

## Install

```sh
composer require atannex/tagging
php artisan migrate
php artisan vendor:publish --tag=tagging-config
```

Provider and facade discovery are automatic.

Migrations load automatically. If you publish migrations with `--tag=tagging-migrations`, set `tagging.load_migrations` to `false` before running them. The package owns `tagging_tags`, `tagging_names`, and `tagging_assignments`.

## Start with any Eloquent model

```php
use Atannex\Tagging\Concerns\HasTags;

class Post extends Model
{
    use HasTags;
}

$post->attachTags(['Laravel', 'Architecture']);
$post->syncTags(['PHP', 'Laravel']);
$post->detachTags(['PHP']);
$post->detachTags(); // Clear this model's default tag space.

$tags = $post->tagSpace()->tags($post);
Post::withAnyTags(['Laravel', 'PHP'])->get();
Post::withAllTags(['Laravel', 'PHP'])->get();
Post::withoutTags(['Archived'])->get();
```

Inputs are iterables of names or persisted `Tag` objects. Strings resolve normalized slugs and aliases. Numeric tag IDs are deliberately not accepted as a separate identifier format: use a `Tag` model. Duplicate names resolve once. Mutations return the model from the trait helpers; space mutations return void, except `detach`, which returns the removed assignment count.

Save the subject first. Integer, UUID, and ULID keys can coexist; keys are stored as strings. Laravel morph maps work and are recommended for stable model identities. Subject keys have a 64-byte limit and morph types a 160-byte limit.

## Tenants, taxonomies, and assignment contexts

```php
use Atannex\Tagging\Facades\Tagging;

$topics = Tagging::for(tenant: 'company-42', taxonomy: 'topics', context: 'editorial');
$topics->attach($post, ['Engineering', 'Laravel']);
$topics->sync($post, ['Architecture']);
$topics->tags($post);

Post::withAllTags(['Architecture'], $topics)->paginate();

// A different use of the same vocabulary:
$seo = Tagging::for('company-42', 'topics', 'seo');
$seo->attach($post, ['Laravel']);
```

A tag identity is `(tenant, taxonomy, normalized slug)`. An assignment identity is `(tag, model type, model key, context)`. Sync and detach stay within the selected space. Passing a Tag from another tenant or taxonomy raises an exception.

The default helpers use tenant `''`, taxonomy `tags`, context `default`. Choose the explicit space for tenant work. This is data partitioning, not authorization: resolve tenant IDs from trusted application state and enforce access with your own policies. A subject may intentionally have assignments from multiple tenants; the package does not infer its owner.

## Rich vocabulary

```php
$tag = $topics->create('Software Architecture', [
    'translations' => ['fr' => 'Architecture logicielle'],
    'metadata' => ['color' => '#6D28D9', 'icon' => 'layers'],
]);
$topics->alias($tag, 'System Design');
$topics->find('System Design'); // Same canonical tag.
$tag->label('fr');             // Translation, or name as fallback.
$tag->label();                 // Current application locale.

$parent = $topics->create('Engineering');
$topics->move($tag, $parent);
$topics->descendants($parent);
$topics->move($tag, null); // Root.

$tag->update(['active' => false]);
```

Canonical slugs and aliases share a unique namespace. Creating an existing name or alias returns its canonical tag without overwriting attributes. Names contain 1–160 characters. Non-transliterable names receive stable hash slugs. Aliases normalize like canonical names; alias collisions are rejected. Translations affect display, not identity or matching. Use aliases for localized lookup names.

Hierarchy edits reject cross-space parents and cycles. Descendant traversal includes all descendants, excluding the root. Matching tags does not implicitly match their descendants. Inactive tags remain stored, disappear from normal reads and matching, and cannot be attached. Existing assignments remain available for reactivation or audit.

Use space methods for identity and tree edits. Direct changes to `slug`, `tenant`, `taxonomy`, or `parent_id`, bulk SQL writes, and disabling foreign keys bypass package invariants.

## Weighted, sourced, expiring assignments

```php
$topics->attach($post, ['Laravel'], [
    'weight' => 0.97,
    'source' => 'classifier-v2',
    'metadata' => ['reviewed' => false, 'run_id' => 'run-123'],
    'expires_at' => now()->addMonth(),
]);

$assignments = $topics->assignments($post)->current()->with('tag')->get();
```

Weights are finite non-negative numbers; they are not restricted to probabilities. Source labels have a 64-byte limit. Attaching again updates only supplied fields; omitted fields retain existing values. New assignments default to weight `1`, source `manual`, and no expiration. Supply `expires_at => null` to clear an expiration. Metadata is JSON, replaced as a whole when supplied.

Expiration is evaluated at read time, including query scopes and popularity. `assignments()` intentionally includes expired rows; apply `current()` for live rows. `tagAssignments` is the raw Eloquent relationship, useful for eager loading; it includes every space and expired/inactive data. `tags()` returns canonical Tag models; use assignments for weights and provenance.

```sh
php artisan tagging:prune --dry-run
php artisan tagging:prune
```

Pruning removes expired assignments across all tenants, not vocabulary tags. Schedule it through your application's scheduler. Soft deletion retains subject assignments for restoration; force deletion and ordinary model deletion remove them. Query-builder bulk deletion does not run model lifecycle hooks.

## Discovery and maintenance

```php
$topics->suggest('Lar', 10); // Name prefix; LIKE wildcards are treated literally.
$topics->popular(20);       // Active tags with usage_count, current context only.
$topics->merge($duplicate, $canonical);
```

Popularity counts current assignments across all subject model types in the space; unused active tags have zero usage. Suggestion limits are clamped to 1–100. Suggestions search canonical display names, not translations or aliases.

Merge operates on the entire tenant/taxonomy, across all contexts and subjects. It moves assignments, keeps target assignment values on identity conflicts, transfers source aliases and its canonical name, reparents direct children, and deletes the source. The target's name, metadata, translations, active state, and parent remain unchanged. Merging into a descendant is rejected.

## Matching semantics

| Input | Any | All | None |
| --- | --- | --- | --- |
| Empty iterable | No models | All models | All models |
| Unknown/inactive name | Does not match that name | No models | Does not exclude models |
| Duplicate names/aliases | Deduplicated | Deduplicated | Deduplicated |

Read queries never create vocabulary. Matching uses exact normalized identities, not substring or fuzzy matching. Scopes require the `HasTags` trait on the queried model.

## Events and transaction behavior

Listen for `Atannex\Tagging\Events\TagsChanged`. Its readonly payload contains `subject`, `operation` (`attach`, `sync`, `detach`), `tagIds`, `tenant`, `taxonomy`, and `context`. Events dispatch after transaction commit. For attach/sync, IDs are the resolved requested tags; for detach, IDs are removed assignments' tags. No-op operations also dispatch. Merge, model cleanup, and pruning do not dispatch this event; it is not a complete audit log.

Batch creation and assignments are transactional: an invalid tag rolls back the whole batch. Operations accept at most 100 tags by default, configurable with `max_tags_per_operation`. Subject rows are locked during attach/sync; hierarchy operations lock the taxonomy's existing rows. Database unique constraints protect identities. Concurrent creation of the same new vocabulary name may raise a unique constraint exception; retry the application transaction. All package data and subjects must use the same default database connection. SQLite tests do not prove MySQL/PostgreSQL concurrency behavior.

## Scope and extension

The package provides a storage and domain API. It does not ship a public HTTP API, admin UI, AI classifier, authorization policy, billing integration, complete audit history, or automatic tenant inference. Build those adapters around TagSpace and application policies. It makes no claim to cover every possible tagging system or to be the first of its kind.

Tenant/context keys are at most 64 bytes; taxonomy and context must be nonempty. The hierarchy uses adjacency lists and loads a taxonomy for descendant traversal, suitable for ordinary editorial vocabularies. Very large ontologies may need a dedicated closure-table implementation. Automatic creation accepts names supplied by your application; validate untrusted input at your application boundary.

## Tests

```sh
composer install
composer test
```

Integration tests use Orchestra Testbench and SQLite in memory. They cover assignment identity, isolated sync/detach, aliases, translations, matching semantics, expiry, pruning, hierarchy, merges, rollback, events, popularity, suggestions, UUID subjects, morph maps, and soft deletion.

## Vocabulary lifecycle

Rename canonical tags without breaking old references:

```php
$tag = $topics->find('Laravel');
$topics->rename($tag, 'Laravel Framework');
$topics->find('Laravel'); // Still resolves to the renamed tag.
$topics->aliases($tag);   // Sorted normalized aliases, excluding canonical slug.
$topics->removeAlias($tag, 'Laravel');

// Discard the immediately previous canonical name on this rename:
$topics->rename($tag, 'Laravel', keepOldName: false);
$path = $topics->ancestors($tag); // Root first, excluding the tag itself.
```

Renaming preserves tag IDs and every assignment. A tag's own alias may become its canonical name. Names owned by other tags cause a collision error and roll back the operation. `keepOldName: false` removes only the previous canonical name; unrelated aliases remain. Removing the current canonical name through `removeAlias()` is forbidden. Alias management and rename apply across all assignment contexts in the tenant/taxonomy.

## Assignment checks and toggles

```php
$post->hasTags(['Laravel', 'PHP']);        // All requested tags, by default.
$post->hasTags(['Laravel', 'PHP'], 'any');
$post->hasTags(['Archived'], 'none');
$result = $post->toggleTags(['Laravel', 'PHP'], ['source' => 'editor']);
// ['attached' => [tag IDs], 'detached' => [tag IDs]]

// Explicit tenant space:
$topics->has($post, ['Architecture']);
$topics->toggle($post, ['Architecture']);
```

Checks use current, active assignments and the same any/all/none semantics as query scopes. They never create tags. Toggle deduplicates names and aliases, removes currently assigned tags, and creates or attaches missing tags. An expired assignment counts as absent; toggling it on clears expiration unless `expires_at` is explicitly supplied. Supplied assignment attributes apply only to attached tags. Toggle is transactional, including tag creation, and emits the existing attach/detach events for its nonempty parts after commit. Invalid or inactive tags reject the whole toggle.

## Related-tag discovery

```php
$related = $topics->related($topics->find('Laravel'), 10);
foreach ($related as $tag) {
    echo $tag->name.' appears together on '.$tag->shared_count.' subjects';
}
```

Related tags rank by the number of shared subjects, descending, with tag ID as a stable tiebreaker. Both sides must have current assignments in the selected context. Results exclude the input tag, inactive tags, other tenants and taxonomies. Subject identity includes model type as well as model key. An inactive input tag returns an empty collection. Limits are clamped to 1–100. This is exact co-occurrence, not semantic AI similarity; application policies remain responsible for deciding which vocabulary analytics users may access.

## Portable vocabulary import and export

```php
$document = $topics->export();
$destination = Tagging::for('company-99', 'topics');
$preview = $destination->import($document, dryRun: true);
// ['created' => 12, 'updated' => 3, 'dry_run' => true]
$report = $destination->import($document);
```

Version 1 documents contain a `tags` list. Each row has `name`, canonical `slug`, nullable parent canonical slug, `aliases` (normalized slugs), `translations`, `metadata`, and boolean `active`. Export produces this schema directly. Tenant identifiers, subject IDs, assignments, and database tag IDs are excluded, allowing a vocabulary to move between tenants or databases. Export covers the entire tenant/taxonomy, including inactive tags, regardless of the current assignment context.

Import is additive: it creates missing canonical tags, updates the supplied attributes and parent on existing canonical tags, and adds aliases. It preserves existing aliases absent from the document, untouched tags, and assignments. Reports count matched canonical rows as updated even when attributes are identical. Names that resolve to another canonical tag's alias are rejected. Slugs must match normalized names. Parents may refer to imported nodes or existing canonical nodes in the destination space.

Malformed rows, duplicate canonical slugs, alias collisions, missing parents, and hierarchy cycles reject the entire import. Valid changes to the shape of an existing tree are supported. Import limits default to 5,000 tags and 100 aliases per tag. CLI files are limited to 10 MiB. Configure these in `config/tagging.php`.

A dry run executes the same database operations inside a rolled-back transaction and suppresses Eloquent observers for temporary vocabulary changes. It preserves surrounding application transactions. Database sequences may advance during a preview depending on the database engine. Run previews against a writable connection; they are simulations rather than read-only queries. Real imports use ordinary Eloquent lifecycle events and do not emit `TagsChanged`, because they modify vocabulary rather than assignments. Application observers should defer external side effects until commit.

```sh
php artisan tagging:export --tenant=company-42 --taxonomy=topics > vocabulary.json
php artisan tagging:import vocabulary.json --tenant=company-99 --taxonomy=topics --dry-run
php artisan tagging:import vocabulary.json --tenant=company-99 --taxonomy=topics
```

Export writes JSON to standard output; import reads a local JSON file and reports created/updated totals. CLI import returns a failure exit code for missing files, malformed JSON, or invalid vocabulary. No migration changes are needed for these features.

## Rule-based automatic tagging

Define application-owned rules, preview matches, then apply them within an explicit space:

```php
use Atannex\Tagging\Automation\Rule;

$rules = [
    Rule::contains(
        name: 'laravel-topic',
        fields: ['title', 'body', 'payload.summary'],
        needles: ['Laravel', 'Eloquent'],
        tags: ['Laravel', 'Backend'],
        mode: 'any',
        weight: 0.9,
    ),
    new Rule(
        name: 'featured-editorial',
        predicate: fn (Post $post) => $post->featured && $post->published,
        tags: ['Featured'],
        weight: 1.0,
    ),
];

$automation = $topics->automation('rules:editorial');
$matches = $automation->preview($post, $rules);
$report = $automation->apply($post, $rules);
// matched_rules: names of matching rules
// attached: tag IDs created/updated on this subject
// detached: stale tag IDs previously owned by rules:editorial
// preserved: matched tag IDs whose assignment belongs to another source
```

`Rule::contains()` performs literal, Unicode case-insensitive substring checks on selected model attributes. JSON attributes may use dot paths. With `all`, every needle must occur in at least one selected field; with `any`, one needle suffices. Missing, null, array, and object field values do not match. String and numeric values are searchable. Needles are not regular expressions or SQL wildcards. HTML and Markdown are not stripped automatically.

Custom predicates receive the supplied model, including in-memory attribute changes. Preview can evaluate an unsaved model; apply requires a saved subject on the tagging connection. Predicates are trusted PHP application code: keep them deterministic and free of writes or external side effects. The package itself creates no tags or assignments during preview; it cannot roll back side effects inside arbitrary callbacks.

Source names must use the `rules:` prefix followed by a nonempty identifier, with a 64-byte total limit. Use a distinct source for each independently managed rule set. Apply synchronizes only that source in the selected tenant, taxonomy, and context. It preserves all assignments owned by other sources, including expired manual assignments. A tag has one assignment per subject/context, so manual and automated ownership do not coexist on the same tag assignment. Manual assignment takes precedence when its source differs from the automation source.

Matching names and aliases collapse to canonical tag IDs. The highest matching rule weight wins, and assignment metadata records all matching rule names under `rules`. Reapplying updates owned assignments rather than duplicating them, and clears their expiration. Inactive matching tags reject the whole operation. Unmatched owned assignments are removed by default; pass `replace: false` to retain them:

```php
$automation->apply($post, $rules, replace: false);
```

Empty rules clear this source's assignments in replacement mode. Rule names must be unique in each evaluation, at most 100 bytes each. Rules require a nonempty list of tag names and finite, non-negative weights. Evaluation defaults to at most 100 rules (`max_automation_rules`). Matching tag entries, before alias deduplication, cannot exceed `max_tags_per_operation`. A predicate exception, invalid match, or mutation failure rolls back package database changes. Attach/detach events dispatch after commit for the corresponding mutations. Application callbacks and model observers remain responsible for deferring external side effects.

## Copy assignments between subjects

```php
$report = $topics->copy($originalPost, $translatedPost);
// ['copied' => [tag IDs], 'skipped' => [tag IDs]]

$topics->copy($originalPost, $translatedPost, overwrite: true);
```

Copy transfers current assignments for active tags within the selected space. Source and destination may be different Eloquent model types. It preserves weights, source labels, metadata, and absolute expiration timestamps; timestamps are not extended. Existing destination assignments, including expired ones, are skipped unless `overwrite: true`. Unrelated destination tags are retained. Copying a subject to itself is a no-op.

Copies are atomic, lock both saved subjects in a consistent order, and enforce the configured per-operation tag limit. Events use the existing attach event on the destination. The original assignments remain unchanged. Copying a `rules:` source also copies its ownership, so the corresponding automation can manage it on the destination. Cross-tenant or cross-taxonomy transfer is intentionally excluded; use vocabulary transfer when moving definitions between spaces.

## Usage within a time window

```php
$usage = $topics->usage(
    since: now()->subDays(7),
    until: now(),
    limit: 20,
    source: 'rules:editorial', // Optional exact source filter.
);

foreach ($usage as $tag) {
    echo $tag->name.': '.$tag->usage_count;
    echo ' total weight: '.($tag->total_weight ?? 0);
}
```

Usage ranks active tags by assignment count, then total weight, then ID. It includes assignments first created in the half-open window `[since, until)` that are still present and unexpired when queried. Tenant, taxonomy, context, and optional source filters apply. `until` defaults to the current time; invalid or reversed windows are rejected. Use timestamps in the timezone used by your database connection. Limits are clamped to 1–100.

Active vocabulary with no matching assignments appears with `usage_count = 0` and `total_weight = null`, consistent with popularity including unused tags. A tag used only in another context can therefore appear as an unused tag in this context. Counts distinguish subject model types through the assignment identity.

This report does not reconstruct deleted or expired assignments, previous source labels, or prior weights. Reattaching an existing assignment retains its original `created_at`; copying creates a new destination assignment timestamp. Use a dedicated audit/event store if you need historical trends or billing-grade attribution.

## Assignment-aware model queries

Combine matching modes with minimum weights, source labels, and category subtrees:

```php
$posts = Post::withTagMatch(
    tags: ['Engineering', 'Editorial'],
    space: $topics,
    mode: 'all',
    minWeight: 0.8,
    source: 'rules:editorial',
    descendants: true,
)->where('published', true)->paginate();

// The equivalent space API, useful when assembling a query dynamically:
$query = $topics->matching(
    Post::query(), ['Engineering'], mode: 'any',
    minWeight: 0.5, source: 'manual', descendants: true,
);
```

Minimum weights are inclusive, finite, and non-negative. Source labels match exactly. Weight and source filters must be satisfied by the same current assignment. Expired assignments and inactive tags do not match. Queries never create vocabulary, preserve application filters, and require `HasTags` and the tagging database connection.

With `descendants: true`, each requested active tag matches itself or any active descendant. An inactive intermediate node does not hide its active descendants; an explicitly requested inactive root is treated as unavailable. In `all` mode, each requested category must have a matching assignment in its own subtree. Two assignments under one category cannot satisfy a separate category. Overlapping categories may be satisfied by the same assignment, and duplicate names/aliases are deduplicated. `any` accepts one branch; `none` excludes subjects matching any branch. Unknown names and empty inputs follow the same semantics as the original query scopes.

Subtree expansion uses the existing in-memory taxonomy traversal. Use ordinary editorial vocabularies rather than very large ontologies with this implementation.

## Similar-content discovery

Find posts, products, or other subjects that share the seed subject's tags:

```php
$recommendations = $topics->similar(
    subject: $post,
    candidates: Post::query()->where('published', true),
    limit: 10,
    minShared: 2,
);

foreach ($recommendations as $candidate) {
    echo $candidate->shared_tags;
    echo $candidate->similarity_weight;
}

// A different model type can share the same taxonomy:
$products = $topics->similar($post, Product::query()->where('available', true));
```

Results are Eloquent models ranked by shared tag count, then weighted overlap, then model key. Weighted overlap is the sum of `seed weight × candidate weight` over shared tags. It is an unnormalized dot product: it is not a percentage, cosine similarity, or a probability. A zero-weight assignment still counts as a shared tag.

Only active tags with current assignments in the selected tenant/taxonomy/context contribute. The seed is excluded when candidate model type and key identify the same subject. Candidate types remain distinct even when their keys match. Candidate filters and global scopes, including soft deletion, apply. Pass `withTrashed()` explicitly if those candidates should participate. The method clones the supplied query, replaces its ordering with similarity ranking, and clamps result limits to 1–100. `minShared` must be at least one.

Use a standard candidate query over the model's original table, returning one row per model. Table aliases, custom grouping/aggregates, union queries, duplicate-producing joins, and conflicting computed-column names are not supported. The returned `shared_tags` and `similarity_weight` names are reserved computed attributes. Custom selects should retain the candidate primary key. The query must use the same database connection as the saved seed and tagging tables. Integer, UUID, and ULID keys are supported; the implementation explicitly casts candidate keys when joining string assignment keys.

An empty or inactive-only seed vocabulary returns no recommendations. This feature measures shared labels rather than semantic content. Application policies must restrict the supplied candidate query to content the current viewer may access.

## Vocabulary health diagnostics

```php
$report = $topics->health();
// healthy, tag_count, issues, unused_tag_ids,
// inactive_assignment_count, expired_assignment_count
```

```sh
php artisan tagging:health --tenant=company-42 --taxonomy=topics --context=editorial
php artisan tagging:health --tenant=company-42 --taxonomy=topics --json
```

Diagnostics detect these structural issues:

| Issue code | Meaning |
| --- | --- |
| `missing_canonical_name` | The tag's canonical slug has no correctly scoped lookup entry. |
| `name_scope_mismatch` | A name entry points outside its tenant/taxonomy or is stored in the wrong namespace. |
| `invalid_parent_scope` | A parent is missing or belongs to another tenant/taxonomy. |
| `hierarchy_cycle` | Parent links form a cycle; one issue is emitted per detected cycle. |

Structural checks cover the entire tenant/taxonomy. Assignment counters use only the requested context. Unused tags have no unexpired assignments in that context; this includes tags with only expired assignments. Live assignments pointing to inactive tags are counted separately. Normal hygiene conditions do not make the vocabulary structurally unhealthy.

The command returns a failure exit code only when structural issues are found. It reports rather than repairs data, and can be used as a maintenance check. It does not delete unused tags, inspect subject tables, detect deleted subjects, repair orphan assignments, or infer ownership. It loads vocabulary and name entries into memory, so run it as an administrative operation on ordinary-sized taxonomies. Concurrent vocabulary edits may affect the report; use a quiet maintenance period when diagnosing suspected corruption. Name-scope issues may reference connected foreign IDs, so expose these diagnostics only to trusted administrators.

These features use the existing schema and require no additional migrations.

## Assignment governance

Configure declarative policies by exact taxonomy name in `config/tagging.php`:

```php
'policies' => [
    'publication-status' => [
        'min_tags' => 1,
        'max_tags' => 1,
        'exclusive' => [['Draft', 'Published', 'Archived']],
    ],
    'access-labels' => [
        'max_tags' => 8,
        'required' => ['Classified'],
        'exclusive' => [['Public', 'Confidential']],
        'dependencies' => [
            'Premium' => ['Verified'],
        ],
        'contexts' => [
            // Context options replace the corresponding taxonomy defaults.
            'internal' => ['max_tags' => 12, 'required' => []],
        ],
    ],
],
```

Policies enforce minimum and maximum active tag counts, mandatory tags, mutually exclusive groups, and tag dependencies. They resolve names and aliases within each tenant's own vocabulary. `exclusive` groups permit at most one distinct canonical tag; aliases of the same tag do not conflict. Dependencies are conditional: assigning `Premium` requires `Verified`, while assigning `Verified` alone is allowed. Required names are never automatically created or attached.

Policies are optional; the default empty configuration keeps existing tagging behavior. Each taxonomy policy applies independently to every subject and tenant in that taxonomy. Context overrides replace individual options rather than recursively merging name lists. Definitions are ordinary arrays compatible with Laravel configuration caching; no tenant identity is stored in policy objects or the container singleton.

The package validates the final active, unexpired assignment set before committing attach, sync, detach, toggle, replace, copy, or automation mutations. A violation rolls back the entire operation, including vocabulary created by that operation. Composite operations validate once for each subject/space at the outermost governance boundary, so a temporary intermediate state does not reject a valid final replacement.

```php
use Atannex\Tagging\Governance\PolicyViolation;

$status = Tagging::for('company-42', 'publication-status');
$status->attach($post, ['Draft']);

try {
    $status->attach($post, ['Published']); // Exceeds max_tags; everything rolls back.
} catch (PolicyViolation $exception) {
    $errors = $exception->violations;
    // [['code' => 'max_tags', 'message' => '...']]
}
```

Structured violation codes are `min_tags`, `max_tags`, `required_tag`, `exclusive_tags`, and `tag_dependency`. Required/dependency/exclusion violations also identify the relevant configured names. Malformed policy configuration raises `InvalidArgumentException`, separately from a valid policy rejecting an assignment set. Configuration keys and option types are checked; unknown options are rejected.

## Transactional tag replacement

Swap selected assignments while keeping unrelated tags:

```php
$result = $status->replace($post, remove: ['Draft'], add: ['Published'], attributes: [
    'source' => 'editor',
    'metadata' => ['reason' => 'Approved for publication'],
]);
// ['attached' => [new tag IDs], 'detached' => [removed tag IDs]]

// Default-space model helper:
$post->replaceTags(['Draft'], ['Published']);
```

Replacement can satisfy an exactly-one-tag policy without exposing a committed empty or conflicting status. Unknown names in `remove` are ignored; names in `add` may create vocabulary. Input tags must belong to the selected tenant/taxonomy. Report IDs describe net differences in stored assignments, including expired/inactive rows. If the same tag is removed and re-added, its ID appears in neither net difference, but its assignment is recreated with the supplied attributes and ordinary defaults. Existing added tags not removed are updated like `attach()`. Attach/detach domain events describe the underlying mutations and dispatch only after commit.

For more complex same-subject changes, compose operations explicitly:

```php
$status->transaction($post, function () use ($status, $post) {
    $status->detach($post, ['Draft']);
    $status->attach($post, ['Published']);
});
```

The callback's result is returned to the caller. An exception rolls back all enclosed database work. The subject is locked and its final assignments are checked before this transaction boundary exits. Compose changes for the same subject/space to defer its validation; distinct subjects or spaces have their own boundaries. An ordinary application `DB::transaction()` alone does not defer policy checks across separate package operations—use `TagSpace::transaction()` for that purpose.

## Assignment validation and sync previews

Inspect an existing subject without making changes:

```php
$violations = $status->validate($post); // [] means the current set satisfies policy.
```

Preview a proposed complete assignment set using the actual sync and policy logic:

```php
$preview = $status->previewSync($post, ['Published'], ['source' => 'editor']);
// [
//     'valid' => true,
//     'violations' => [],
//     'tags' => ['Published'], // Active canonical display names in the proposed set.
// ]
```

Invalid final policies return `valid: false` with structured violations and the proposed active tag names. Invalid input, inactive tags, or malformed policy configuration still raise their normal exceptions. Preview rolls back temporary tags and assignments, suppresses Eloquent model observers for those temporary records, and never commits assignment domain events. Surrounding database transactions and governance scopes remain usable. Database sequences may advance, depending on the database engine. A preview requires a writable connection and is not an approval token; the real mutation rechecks current policy and state.

Governance protects package assignment mutations rather than all database changes. It does not authorize users, validate arbitrary SQL or direct `Assignment` writes, or block model deletion, pruning, vocabulary merges/imports, alias changes, or tag deactivation. Expiration and vocabulary maintenance may make existing subjects fail a policy later; call `validate()` when a business action requires current compliance. For example, revalidate before publishing if a required classification can expire. Health diagnostics check structural integrity, not policy compliance across subject tables. Ensure custom model observers defer external effects until commit, since rollback cannot undo external actions.

New governance behavior is covered by integration tests for cardinality, aliases, exclusions, dependencies, final-state replacement, nested transactions, previews, automation, copy, expiration, context overrides, and rollback. No schema changes are required.

## Faceted search counts

Build tag filters from the content the current viewer can access:

```php
$candidates = Post::query()
    ->where('published', true)
    ->where('author_id', $authorId);

$facets = $topics->facets(
    candidates: $candidates,
    limit: 20,
    minWeight: 0.5,
    source: 'editor',
);

foreach ($facets as $tag) {
    echo $tag->label().' ('.$tag->facet_count.')';
    echo ' weight: '.$tag->total_weight;
}
```

Facets count distinct candidate models with each tag, using current assignments in the selected tenant, taxonomy, and context. Inactive tags are excluded. Minimum weight is inclusive and must be finite and non-negative; source filtering is exact. Results rank by count, total assignment weight, and tag ID, with limits clamped to 1–100.

The candidate query's application filters and global scopes apply, including soft deletion. Pagination, offsets, custom selections, and ordering are deliberately removed from a clone so counts describe the entire filtered result. Joins that produce repeated candidate rows do not inflate counts. The original builder remains reusable. Integer, UUID, ULID, and morph-map identities work. Use an unaliased model query on the tagging connection; grouped queries, HAVING clauses, and unions are rejected.

Unused tags are omitted by default. To include active vocabulary with no matching candidates, use `includeUnused: true`; zero-count facets have `facet_count = 0` and `total_weight = 0`:

```php
$allOptions = $topics->facets($candidates, includeUnused: true);
```

Existing tag filters on the candidate query remain in effect, so counts describe that already-filtered result. To build disjunctive facets that ignore a currently selected tag filter, supply a candidate query without that filter. Always restrict candidates using application authorization. With `includeUnused`, labels are drawn from the whole selected vocabulary even when no candidate uses them, so expose that option only when the viewer may see the vocabulary.

## Explicit tag activation and deactivation

```php
$tag = $topics->find('Legacy');
$changedIds = $topics->deactivate($tag);
$topics->activate($tag);

// Opt into changing the complete category subtree:
$topics->deactivate($tag, descendants: true);
$topics->activate($tag, descendants: true);
```

Activation changes vocabulary status across every subject and context in its tenant/taxonomy. Assignments, aliases, metadata, and hierarchy are retained. Deactivation removes tags from active reads, matching, facets, and recommendations; reactivation makes unexpired assignments visible again. It does not extend expiration or recreate deleted assignments. Descendants remain unchanged unless explicitly included, and cascade activation enables every descendant even if a child had been inactive before the parent was deactivated.

Each operation is transactional and returns only IDs whose status changed, ordered by ID. Repeating the same status change returns an empty list. Cross-tenant/taxonomy tags are rejected. Cascade operations lock the existing taxonomy rows; direct single-tag changes lock the tag row. The supplied tag model is refreshed after success. As with ordinary Eloquent updates, refresh an in-memory model after an enclosing application transaction is rolled back.

Listen for `Atannex\Tagging\Events\TagStatusChanged`. The readonly payload contains `tagId`, `tenant`, `taxonomy`, `wasActive`, and `isActive`. One event is emitted per changed tag, only after commit; no-op calls and rolled-back changes emit none. These vocabulary events are separate from assignment `TagsChanged` events. Direct model updates, creation, import, and merge do not emit this lifecycle event. It is an integration hook rather than a persistent audit log.

Status changes are vocabulary maintenance and do not enforce assignment policies for every affected subject. Deactivating a required tag can make existing subjects noncompliant; use `validate()` before policy-sensitive business actions. Application authorization controls who may change vocabulary status.

## Duplicate-vocabulary suggestions

```php
$pairs = $topics->potentialDuplicates(
    maxDistance: 1,
    limit: 20,
    includeInactive: false,
);

// Each suggestion:
// [
//     'left' => ['id' => 12, 'name' => 'Java Script', 'slug' => 'java-script'],
//     'right' => ['id' => 19, 'name' => 'JavaScript', 'slug' => 'javascript'],
//     'distance' => 0,
// ]
```

Suggestions compare canonical display names within the selected tenant/taxonomy, independent of assignment context. Normalization folds case and transliterable accents, removes punctuation and spacing, and preserves non-Latin characters that have no ASCII transliteration. Edit distance measures Unicode code-point insertions, deletions, and substitutions. A zero threshold finds names such as `Java Script` and `JavaScript` that normalize identically; thresholds 1–3 also find close spellings. Aliases and translations are not compared. Punctuation-only names are skipped.

Results sort by ascending distance, then the two tag IDs. Limits are clamped to 1–100. Inactive tags are excluded unless requested. No names, assignments, or tag identities are changed. Similar spelling can represent different concepts—`C++` and `C#`, for example—so review suggestions before using the existing `merge()` API.

To bound pairwise work, scans default to at most 300 eligible tags (`max_duplicate_scan_tags` in configuration). Oversized vocabularies raise `LengthException` rather than silently scanning a sample; invalid distances outside 0–3 raise `InvalidArgumentException`. The implementation uses bounded edit-distance computation, but raising the vocabulary limit still increases pairwise work. For large ontologies, use a dedicated indexed similarity service or separate taxonomies.

These features use the existing schema; no additional migration is needed.

## Bulk assignment workflows

Apply an assignment operation to an application-filtered model query:

```php
$report = $topics->bulk(
    candidates: Post::query()->where('published', true),
    tags: ['Engineering', 'Laravel'],
    operation: 'attach', // attach, sync, or detach
    attributes: ['source' => 'editor', 'weight' => 0.9],
    dryRun: false,
    atomic: true,
);
```

The default atomic mode commits all successful subject operations only when no subject fails. Policy violations or invalid assignment data produce per-subject failures; any such failure rolls back the entire batch, including newly created vocabulary and deferred domain events. To commit eligible subjects independently and continue past expected failures, use `atomic: false`.

```php
$preview = $topics->bulk($candidates, ['Backend'], operation: 'sync', dryRun: true);
$partial = $topics->bulk($candidates, ['Backend'], atomic: false);
```

Reports contain:

| Field | Meaning |
| --- | --- |
| `operation`, `dry_run`, `atomic` | Requested execution mode. |
| `targeted` | Number of candidate keys snapshotted before mutation. |
| `succeeded` | Subject operations that passed, including no-op mutations. |
| `failed` | Subject operations rejected by policy or invalid assignment arguments. |
| `skipped` | Snapshotted subjects that no longer match when rechecked. |
| `applied` | Successful subject operations retained after the batch boundary; zero after a full rollback. |
| `rolled_back` | Whether the entire batch was rolled back for preview or atomic failure. |
| `results` | Ordered per-subject records with string `key` and `status`; failures include a code and details. |

`policy_violation` records contain the existing structured `violations` list. `invalid_assignment` records contain a message. A succeeded record inside an atomic failure report describes an attempted operation, not a committed change; inspect `applied` and `rolled_back` before reporting completion.

Bulk snapshots keys in primary-key order, then rechecks each subject against the original filters while locking it for mutation. Later inserts are not added to the snapshot. Records deleted or changed so they no longer qualify are skipped. Tag-dependent filters are supported without losing later subjects as earlier assignments change. Candidate scopes, including default soft deletion, apply. Each operation also uses the normal assignment governance layer. The original builder is cloned and remains reusable.

Use an unaliased query on the tagging connection without joins, grouping, HAVING, unions, limits, or offsets. Restrict the candidate query explicitly rather than passing a paginated query. Subjects must be writable through their model's ordinary scopes; exclude soft-deleted subjects from mutation candidates. Tag inputs may be names, Tag objects, or a generator, and are materialized once with the normal per-operation input limit. Cross-space Tag objects are rejected before mutation.

For sync, an empty list clears this space's assignments, subject to policy. For detach, an empty list removes nothing; it is not the same as `detach($subject, null)`. Unknown detach names are ignored. Detach rejects assignment attributes because it does not update them. Attach/sync attributes behave as on individual mutations. Inactive attach/sync tags are rejected during preflight, before subject writes.

To bound memory, locks, and report size, bulk operations default to at most 1,000 subjects (`max_bulk_subjects`). Oversized queries raise `LengthException` before any assignment mutation, rather than processing a silent subset. Atomic mode retains locks until the batch completes. For larger jobs, partition candidates into explicit key ranges in your queue/application; a builder itself is not a serialized queue payload.

A dry run simulates the complete batch inside a rolled-back transaction, even when `atomic: false`. Temporary Eloquent model observers are suppressed and assignment domain events do not commit. Database sequences may advance, and observers intentionally suppressed for preview can make real execution differ. It needs a writable connection and is not an approval token.

Unexpected database errors, programming exceptions, or after-commit listener failures propagate instead of becoming ordinary per-subject failures. In non-atomic mode, earlier subjects may already be committed when such an exception occurs. An after-commit listener cannot undo the commit that triggered it. If an enclosing application transaction exists, reported applied operations are staged until that outer transaction commits. Choose candidate filters using application authorization and defer external observer side effects until commit.

## Laravel tag-selection validation

Validate incoming name lists against the active vocabulary before calling mutation APIs:

```php
use Atannex\Tagging\Rules\TagSelection;

$validated = $request->validate([
    'topics' => [
        'present',
        'array',
        new TagSelection($topics, min: 1, max: 8),
    ],
]);

$topics->sync($post, $validated['topics']);
```

The rule accepts list arrays of existing active tag names and aliases in the selected tenant/taxonomy. Unknown, inactive, blank, overlong, non-string, nested, or associative inputs fail. It never creates vocabulary or assignments. By default, names and aliases that resolve to the same canonical tag are considered duplicates:

```php
new TagSelection($topics, min: 0, max: 20, distinct: false);
```

Minimum and maximum bounds count submitted entries, before canonical deduplication. Bounds must be non-negative, ordered, and no greater than `max_tags_per_operation`; the default maximum uses that configuration. Disabling `distinct` permits repeated names/aliases in the request, while assignment APIs still deduplicate them.

Like ordinary non-implicit Laravel validation rules, missing or empty-string fields should be handled with `present`, `required`, or `nullable` according to the form's intent. The rule verifies available vocabulary and input shape, not authorization, source ownership, or assignment-policy compliance. Existing governance rechecks final state inside the mutation transaction; use `previewSync()` when a UI needs proposed-state policy feedback.

English validation messages are bundled under `tagging::validation`. Publish them for translation or application-specific wording:

```sh
php artisan vendor:publish --tag=tagging-translations
```

Add translations under `lang/vendor/tagging/{locale}/validation.php` using the bundled message keys. Messages support Laravel's attribute labels and min/max replacements. These features require no schema changes.

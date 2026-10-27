# Contextual reads and eager loading

Existing any/all/none helpers and facade calls remain available. Read queries never create vocabulary. Exact normalized tag identities and aliases are used; translations affect display rather than identity.

| Requested inputs | Any | All | None |
| --- | --- | --- | --- |
| Empty | No subjects | All otherwise eligible subjects | All otherwise eligible subjects |
| Unknown/inactive name | That name does not match | No subjects | That name excludes nothing |
| Duplicates/aliases | Canonically deduplicated | Canonically deduplicated | Canonically deduplicated |

```php
$topics->matching(
    Article::query(),
    ['Engineering', 'Editorial'],
    mode: 'all',
    minWeight: 0.5,
    maxWeight: 3.0,
    source: 'editor',
    descendants: true,
    ancestors: false,
    includeRoot: true,
    expiration: 'current',
)->paginate();
```

The equivalent `withTagMatch()` model scope accepts these options after the existing parameters. Each requested category is a separate group in `all` mode. Two descendants of one root cannot satisfy a different root. Overlapping category groups may be satisfied by the same assignment.

Ancestor/subtree expansion is explicit. `includeRoot=true` includes the requested tag itself; false includes only selected relatives. Active descendants below an inactive intermediate node can match. An explicitly requested inactive root is unavailable. When neither direction is selected and root inclusion is false, the group has no matches. All source, weight, expiration, and tag conditions apply to **the same assignment row**. Weight bounds are inclusive, finite, non-negative, and ordered. They are not probability limits.

Expiration accepts `current`, `expired`, or `all`. Current means null expiration or strictly later than now; an assignment expiring exactly now is expired. Inactive tags remain unavailable to model matching even when expired/all assignments are requested. Exists predicates preserve subject uniqueness and normal pagination.

## Assignment queries

```php
use Atannex\Tagging\Query\AssignmentFilter;

$assignments = $topics->currentAssignments($article, new AssignmentFilter(
    minWeight: 0.5,
    maxWeight: 3.0,
    source: 'editor',
    expiration: 'all',
    orderBy: 'weight',
    direction: 'desc',
    includeInactive: false,
))->with('tag')->get();
```

The default filter is current assignments and active tags. `currentAssignments()` permits an explicit expiration override despite its default-oriented name. Safe ordering fields are `id`, `weight`, `created_at`, and `expires_at`, with ID as a stable tiebreaker. Nullable expiration ordering follows the database's normal null order.

`assignments($article)` remains the lower-level scoped builder including expired/inactive data. The raw `tagAssignments` relationship remains completely unfiltered across spaces and expiration/activity. Its MorphMany subclass only makes heterogeneous key comparisons portable; it does not change raw row coverage. Prefer contextual helpers in tenant-facing code.

## Batch eager loading

```php
$articles = Article::where('published', true)->limit(100)->get();
$loaded = $topics->eagerLoad($articles);
foreach ($articles as $article) {
    $loaded->assignments($article); // Already hydrated, with tag relation loaded.
    $loaded->tags($article);        // No additional SQL.
}
```

`LoadedAssignments` is a per-call snapshot, not a mutable global or model relationship cache. It is tied to the selected space and filter, so loading another space cannot overwrite the first. Passing a model absent from the snapshot raises `ScopeViolation`.

The loader accepts mixed model types, validates the selected connection and access contract, and batches at most 500 supplied identities per assignment query. Each nonempty batch normally uses one assignment query plus one tag query. With ownership integration, one additional fresh-membership query per morph type/batch prevents stale loaded ownership from bypassing restrictions. Query-count tests cover two queries without ownership and three for one owned type, followed by zero SQL when consuming loaded data. Application hooks should avoid their own per-model queries.

This is a snapshot and is not expiration-aware caching. Reuse only within the business operation that loaded it; reload after writes, time changes, or permission changes. No optional cross-request cache is installed.

## Discovery APIs

`facets($candidates, ...)` counts distinct models in the entire filtered query, ignoring pagination on a clone. It honors candidate access/global scopes and optional assignment source/weight filters; `includeUnused=true` includes zero-count active vocabulary. `similar($subject, $candidates, ...)` ranks candidates by shared current active tags, weighted overlap, then key. The seed is excluded by morph type and key, not key alone. Caller filters remain in effect.

`popular()`, `usage()`, and `related()` remain scoped to tenant/taxonomy/context. When an access adapter is enabled, their heterogeneous-subject aggregates apply its query restrictions per safely resolvable morph type. Unknown types contribute no values. Vocabulary labels themselves are not an authorization system: do not expose an entire vocabulary merely because some subject is accessible.

See the [API cookbook](api.md) for suggestions, co-occurrence, weighted overlap semantics, and time-window reports. Usage counts surviving, unexpired assignments first created in the requested half-open time window; it is not historical event reconstruction.

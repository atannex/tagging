# Tenants, policies, and queued execution

A space is selected explicitly with `Tagging::for($tenant, $taxonomy, $context)`. Tenant/taxonomy/context keys are exact strings of at most 64 bytes. Default keys remain `''`, `tags`, and `default`. MySQL upgrade migrations align identity comparisons with SQLite/PostgreSQL, including case and trailing spaces.

```php
use Atannex\Tagging\Facades\Tagging;
use Illuminate\Support\Facades\Gate;

Gate::authorize('update', $article); // Your application's policy.
$space = Tagging::for($trustedTenantId, 'topics', 'editorial');
$space->attach($article, ['Engineering']);
```

Never accept a tenant selector from an untrusted request without checking membership/authorization first. Tenant partitioning is not authorization. The default engine intentionally permits a subject to have assignments in multiple tenants; there is no inferred owner.

## Strict mode

Set `strict_tenants=true` to reject implicit selection, including default model helpers. `Tagging::for('')` is an explicitly selected global tenant; `Tagging::for()` is implicit and rejected. The model helper can select explicitly with `$article->tagSpace(tenant: '')` or a nonempty trusted tenant. Existing serialized spaces retain whether selection was explicit and are checked again when used. Omission detection also covers named arguments such as `for(taxonomy: 'topics')`; those do not count as tenant selection.

Import always requires an explicitly selected destination, independently of strict mode. CLI import requires `--tenant`, including `--tenant=` when the empty tenant is intended. Strict CLI export/health also require this option. Global maintenance commands remain explicitly administrative operations and retain their global default.

## Optional ownership integration

Configure an application class implementing `Contracts\SubjectAccess`. A concrete assertion must throw `ScopeViolation` when inaccessible. `scope()` must restrict the builder without executing it and must fail closed for unsupported model types.

The following application-owned interface avoids assuming that every model has an ownership column:

```php
use Atannex\Tagging\Contracts\SubjectAccess;
use Atannex\Tagging\Exceptions\ScopeViolation;
use Atannex\Tagging\TagSpace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

interface TenantOwned
{
    public function tenantKey(): string;
    public static function tenantColumn(): string;
}

final class OwnedSpaceAccess implements SubjectAccess
{
    public function assertAccess(TagSpace $space, Model $subject): void
    {
        if (!$subject instanceof TenantOwned || $subject->tenantKey() !== $space->tenant) {
            throw new ScopeViolation('Subject is outside the selected tenant.');
        }
    }

    public function scope(TagSpace $space, Builder $query): Builder
    {
        $model = $query->getModel();
        if (!$model instanceof TenantOwned) {
            return $query->whereRaw('1 = 0');
        }
        return $query->where($model->qualifyColumn($model::tenantColumn()), $space->tenant);
    }
}
```

Set `subject_access=OwnedSpaceAccess::class`. Implement `TenantOwned` on your own models using a canonical immutable ownership ID and a matching exact database comparison. This is an example application restriction, not a package dependency. A shared-subject application may instead explicitly allow designated shared types.

Subject reads check both the supplied model and current membership in the restricted query. Writes additionally check a fresh locked subject row. Candidate query scopes, recommendations, facets, bulk work, and contextual eager loading use the same contract. Aggregate APIs without candidate builders scope each safely resolvable morph type; unresolved types contribute no aggregate values. Eager loading batches fresh ownership queries per morph type, avoiding one query per model. Hook implementations themselves must avoid hidden per-model database lookups.

The raw `tagAssignments` relationship remains unfiltered and is not an ownership-enforcing helper. Direct SQL and direct Assignment/Tag updates bypass these contracts. Diagnostics, pruning, and retention are administrative operations; restrict command access separately.

## Queues and long-running workers

```php
use Atannex\Tagging\Jobs\TagSubject;

dispatch(new TagSubject(
    tenant: $trustedTenantId,
    subjectType: $article->getMorphClass(),
    subjectKey: (string) $article->getKey(),
    tags: ['Engineering'],
    taxonomy: 'topics',
    context: 'editorial',
    operation: 'attach',
    attributes: ['source' => 'rules:classifier'],
    actorId: $explicitActorId,
    operationId: $requestCorrelationId,
));
```

The job serializes explicit identifiers and tag names, not an Eloquent model snapshot or a global current tenant. It resolves the morph mapping, current subject query, ownership hook, taxonomy rules, and source policy at execution. It supports attach/sync/detach; detach accepts no attributes. A changed owner, missing subject, or changed policy fails rather than inheriting old request permission. Your queue retry policy controls exhausted domain/database failures.

Do not mutate global config or bind a mutable “current tenant” singleton between jobs. `Tagging` is a stateless factory. Assignment governance uses request/job-scoped bookkeeping that is also cleared in `finally` after each boundary. Actor and operation IDs are nullable, explicit, and at most 128 bytes; `withOperation()` returns a new space.

Legacy v1 serialized spaces are restored with nullable operation IDs. A nonempty legacy tenant proves selection was explicit; a legacy empty tenant cannot establish that intent in strict mode. Drain or re-envelope such queued work before enabling strict mode. Never deserialize arbitrary user-supplied PHP objects.

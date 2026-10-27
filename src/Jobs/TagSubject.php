<?php

namespace Atannex\Tagging\Jobs;

use Atannex\Tagging\Exceptions\PolicyRejected;
use Atannex\Tagging\Exceptions\ScopeViolation;
use Atannex\Tagging\Tagging;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/** Serialize identity and explicit space only. Access and taxonomy rules are rechecked at execution. */
final class TagSubject implements ShouldQueue
{
    public function __construct(
        public readonly string $tenant,
        public readonly string $subjectType,
        public readonly string $subjectKey,
        public readonly array $tags,
        public readonly string $taxonomy = 'tags',
        public readonly string $context = 'default',
        public readonly string $operation = 'attach',
        public readonly array $attributes = [],
        public readonly ?string $actorId = null,
        public readonly ?string $operationId = null,
    ) {}

    public function handle(Tagging $tagging): void
    {
        if (! in_array($this->operation, ['attach', 'sync', 'detach'], true)) {
            throw new PolicyRejected('Unsupported queued tagging operation.');
        }
        foreach ($this->tags as $tag) {
            if (! is_string($tag)) {
                throw new PolicyRejected('Queued tags must be names, not model objects.');
            }
        }
        $class = Relation::getMorphedModel($this->subjectType) ?? $this->subjectType;
        if (! is_subclass_of($class, Model::class)) {
            throw new ScopeViolation('Queued subject morph type cannot be resolved.');
        }
        $space = $tagging->for($this->tenant, $this->taxonomy, $this->context)->withOperation($this->actorId, $this->operationId);
        $query = $space->scopeSubjects((new $class)->newQuery());
        $subject = $query->findOrFail($this->subjectKey);
        if ($this->operation === 'detach') {
            if ($this->attributes) {
                throw new PolicyRejected('Queued detach does not accept assignment attributes.');
            }
            $space->detach($subject, $this->tags);
        } else {
            $space->{$this->operation}($subject, $this->tags, $this->attributes);
        }
    }
}

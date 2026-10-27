<?php

namespace Atannex\Tagging\Query;

use Atannex\Tagging\Exceptions\PolicyRejected;
use Atannex\Tagging\Models\Assignment;
use Illuminate\Database\Eloquent\Builder;

final class AssignmentFilter
{
    public function __construct(
        public readonly ?float $minWeight = null,
        public readonly ?float $maxWeight = null,
        public readonly ?string $source = null,
        public readonly string $expiration = 'current',
        public readonly string $orderBy = 'id',
        public readonly string $direction = 'asc',
        public readonly bool $includeInactive = false,
    ) {
        foreach ([$minWeight, $maxWeight] as $weight) {
            if ($weight !== null && (! is_finite($weight) || $weight < 0)) {
                throw new PolicyRejected('Weight filters must be finite and non-negative.');
            }
        }
        if ($minWeight !== null && $maxWeight !== null && $minWeight > $maxWeight) {
            throw new PolicyRejected('Minimum weight exceeds maximum weight.');
        }
        if (! in_array($expiration, ['current', 'expired', 'all'], true) || ! in_array($orderBy, ['id', 'weight', 'created_at', 'expires_at'], true) || ! in_array($direction, ['asc', 'desc'], true)) {
            throw new PolicyRejected('Invalid assignment filter or ordering.');
        }
    }

    /** @param Builder<Assignment> $query
     * @return Builder<Assignment>
     */
    public function apply(Builder $query, bool $order = false): Builder
    {
        if ($this->expiration === 'current') {
            $query->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
        } elseif ($this->expiration === 'expired') {
            $query->whereNotNull('expires_at')->where('expires_at', '<=', now());
        }
        $query->when($this->minWeight !== null, fn ($q) => $q->where('weight', '>=', $this->minWeight))
            ->when($this->maxWeight !== null, fn ($q) => $q->where('weight', '<=', $this->maxWeight))
            ->when($this->source !== null, fn ($q) => $q->where('source', $this->source));
        if ($order) {
            $query->orderBy($this->orderBy, $this->direction)->orderBy('id');
        }

        return $query;
    }
}

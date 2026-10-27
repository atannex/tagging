<?php

namespace Atannex\Tagging\Operations;

use Atannex\Tagging\Events\AssignmentsChanged;
use Atannex\Tagging\Events\MaintenanceCompleted;
use Atannex\Tagging\Exceptions\InvalidConfiguration;
use Atannex\Tagging\History\Recorder;
use Atannex\Tagging\History\ReviewService;
use Atannex\Tagging\Models\Tag;
use Atannex\Tagging\Support\Settings;
use Atannex\Tagging\Support\SpaceTransactions;
use Atannex\Tagging\TagSpace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class Maintenance
{
    private function size(): int
    {
        Settings::validate();
        $size = config('tagging.maintenance_batch_size', 200);
        if (! is_int($size) || $size < 1 || $size > 1000) {
            throw new InvalidConfiguration('maintenance_batch_size must be between 1 and 1000.');
        }

        return $size;
    }

    public function prune(?string $tenant = null, ?string $taxonomy = null, ?string $context = null, bool $dryRun = false): int
    {
        $query = DB::table('tagging_assignments')->whereNotNull('expires_at')->where('expires_at', '<=', now())
            ->when($context !== null, fn ($q) => $q->where('context', $context))
            ->whereIn('tag_id', Tag::query()->select('id')->when($tenant !== null, fn ($q) => $q->where('tenant', $tenant))->when($taxonomy !== null, fn ($q) => $q->where('taxonomy', $taxonomy)));
        $count = $dryRun ? $query->count() : $this->removeAssignments($query, 'prune');
        if (! $dryRun) {
            event(new MaintenanceCompleted('prune', $tenant, $taxonomy, $context, $count));
        }

        return $count;
    }

    /** Each bounded group commits independently unless an application transaction encloses maintenance. */
    public function removeAssignments(Builder $query, string $operation, ?TagSpace $operationSpace = null): int
    {
        $count = 0;
        (clone $query)->orderBy('id')->chunkById($this->size(), function ($rows) use (&$count, $operation, $query, $operationSpace) {
            $groups = [];
            foreach ($rows as $row) {
                $tag = Tag::query()->find($row->tag_id);
                if ($tag) {
                    $groups[json_encode([$tag->tenant, $tag->taxonomy], JSON_THROW_ON_ERROR)][] = $row->id;
                }
            }
            ksort($groups);
            foreach ($groups as $key => $ids) {
                [$tenant, $taxonomy] = json_decode($key, true, 512, JSON_THROW_ON_ERROR);
                $space = new TagSpace($tenant, $taxonomy, 'default', true);
                $count += app(SpaceTransactions::class)->run($space, function () use ($query, $ids, $operation, $space, $operationSpace) {
                    $current = (clone $query)->whereIn('id', $ids)->lockForUpdate()->get();
                    $deleted = 0;
                    foreach ($current as $row) {
                        $selected = new TagSpace($space->tenant, $space->taxonomy, $row->context, true, $operationSpace?->actorId, $operationSpace?->operationId);
                        $class = Relation::getMorphedModel($row->taggable_type) ?? $row->taggable_type;
                        $subject = is_subclass_of($class, Model::class) ? (new $class)->newQueryWithoutScopes()->find($row->taggable_id) : null;
                        $before = [
                            'tag_id' => $row->tag_id,
                            'weight' => (float) $row->weight,
                            'source' => $row->source,
                            'metadata' => $row->metadata === null ? null : json_decode($row->metadata, true),
                            'expires_at' => $row->expires_at,
                        ];
                        app(Recorder::class)->persist($selected, 'assignment.'.$operation, $row->tag_id, $before, null, $subject, ['type' => $row->taggable_type, 'key' => (string) $row->taggable_id]);
                        DB::table('tagging_assignments')->where('id', $row->id)->delete();
                        $deleted++;
                        if (config('tagging.maintenance_deltas', false)) {
                            event(new AssignmentsChanged($row->taggable_type, (string) $row->taggable_id, $space->tenant, $space->taxonomy, $row->context, [], [$before], []));
                        }
                    }

                    return $deleted;
                });
            }
        });

        return $count;
    }

    public function cleanup(Model $subject): void
    {
        DB::transaction(function () use ($subject) {
            $query = DB::table('tagging_assignments')->where('taggable_type', $subject->getMorphClass())->where('taggable_id', (string) $subject->getKey());
            $count = $this->removeAssignments($query, 'subject_cleanup');
            app(ReviewService::class)->remove(DB::table('tagging_decisions')->where('taggable_type', $subject->getMorphClass())->where('taggable_id', (string) $subject->getKey()), 'subject_cleanup');
            event(new MaintenanceCompleted('subject_cleanup', null, null, null, $count, $subject->getMorphClass(), (string) $subject->getKey()));
        }, DB::transactionLevel() === 0 ? config('tagging.transaction_attempts', 3) : 1);
    }

    public function history(?string $tenant = null, ?string $taxonomy = null, ?int $days = null, bool $dryRun = false): int
    {
        Settings::validate();
        $days ??= config('tagging.provenance.retention_days', 365);
        if (! is_int($days) || $days < 1) {
            throw new InvalidConfiguration('History retention days must be positive.');
        }
        $query = DB::table('tagging_changes')->where('created_at', '<', now()->subDays($days))
            ->when($tenant !== null, fn ($q) => $q->where('tenant', $tenant))->when($taxonomy !== null, fn ($q) => $q->where('taxonomy', $taxonomy));
        if ($dryRun) {
            return $query->count();
        }
        $count = 0;
        $query->orderBy('id')->chunkById($this->size(), function ($rows) use (&$count) {
            $count += DB::table('tagging_changes')->whereIn('id', $rows->pluck('id'))->delete();
        });
        event(new MaintenanceCompleted('history_prune', $tenant, $taxonomy, null, $count));

        return $count;
    }
}

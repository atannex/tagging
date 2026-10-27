<?php

namespace Atannex\Tagging\History;

use Atannex\Tagging\Events\DecisionChanged;
use Atannex\Tagging\Exceptions\PolicyRejected;
use Atannex\Tagging\Models\Tag;
use Atannex\Tagging\Support\Inputs;
use Atannex\Tagging\Support\SpaceTransactions;
use Atannex\Tagging\TagSpace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class ReviewService
{
    public function __construct(private readonly ?TagSpace $space = null) {}

    public function decide(Model $subject, iterable $tags, string $decision): void
    {
        if ($this->space === null) {
            throw new PolicyRejected('Review decisions require a selected space.');
        }
        if (! config('tagging.review_tracking', false)) {
            throw new PolicyRejected('Review tracking must be enabled explicitly.');
        }
        if (! in_array($decision, ['approved', 'rejected'], true)) {
            throw new PolicyRejected('Decision must be approved or rejected.');
        }
        $tags = Inputs::bounded($tags);
        if (count($tags) > config('tagging.max_tags_per_operation', 100)) {
            throw new PolicyRejected('Too many review tags.');
        }
        $this->space->transaction($subject, function () use ($subject, $tags, $decision) {
            $seen = [];
            foreach ($tags as $input) {
                $tag = $this->space->find($input) ?? throw new PolicyRejected('Review decisions require existing vocabulary.');
                if (isset($seen[$tag->id])) {
                    continue;
                }
                $seen[$tag->id] = true;
                $key = ['tag_id' => $tag->id, 'taggable_type' => $subject->getMorphClass(), 'taggable_id' => (string) $subject->getKey(), 'context' => $this->space->context];
                $previous = DB::table('tagging_decisions')->where($key)->first();
                if ($previous?->decision === $decision) {
                    continue;
                }
                $after = ['decision' => $decision, 'actor_id' => $this->space->actorId, 'operation_id' => $this->space->operationId];
                DB::table('tagging_decisions')->updateOrInsert($key, [...$after, 'created_at' => $previous->created_at ?? now(), 'updated_at' => now()]);
                if ($decision === 'rejected') {
                    $this->space->detach($subject, [$tag]);
                }
                $before = $previous ? ['decision' => $previous->decision, 'actor_id' => $previous->actor_id, 'operation_id' => $previous->operation_id] : null;
                app(Recorder::class)->persist($this->space, 'review.'.$decision, $tag->id, $before, $after, $subject);
                event(new DecisionChanged($subject->getMorphClass(), (string) $subject->getKey(), $tag->id, $this->space->tenant, $this->space->taxonomy, $this->space->context, $before, $after));
            }
        });
    }

    public function remove(Builder $query, string $operation, ?TagSpace $operationSpace = null): void
    {
        (clone $query)->orderBy('id')->chunkById(200, function ($rows) use ($query, $operation, $operationSpace) {
            foreach ($rows as $row) {
                $tag = Tag::query()->find($row->tag_id);
                if (! $tag) {
                    continue;
                }
                $space = new TagSpace($tag->tenant, $tag->taxonomy, $row->context, true, $operationSpace?->actorId, $operationSpace?->operationId);
                app(SpaceTransactions::class)->run($space, function () use ($query, $row, $space, $operation) {
                    $current = (clone $query)->where('id', $row->id)->lockForUpdate()->first();
                    if (! $current) {
                        return;
                    }
                    $before = ['decision' => $current->decision, 'actor_id' => $current->actor_id, 'operation_id' => $current->operation_id];
                    DB::table('tagging_decisions')->where('id', $row->id)->delete();
                    app(Recorder::class)->persist($space, 'review.'.$operation, $row->tag_id, $before, null, null, ['type' => $row->taggable_type, 'key' => (string) $row->taggable_id]);
                    event(new DecisionChanged($row->taggable_type, (string) $row->taggable_id, $row->tag_id, $space->tenant, $space->taxonomy, $space->context, $before, null));
                });
            }
        });
    }
}

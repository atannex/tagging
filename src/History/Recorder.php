<?php

namespace Atannex\Tagging\History;

use Atannex\Tagging\Events\AssignmentsChanged;
use Atannex\Tagging\Events\VocabularyChanged;
use Atannex\Tagging\Models\Assignment;
use Atannex\Tagging\TagSpace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class Recorder
{
    public function snapshot(TagSpace $space, Model $subject): array
    {
        return $space->assignments($subject)->orderBy('tag_id')->get()->mapWithKeys(fn (Assignment $assignment) => [$assignment->tag_id => $this->assignment($assignment)])->all();
    }

    public function assignment(Assignment $assignment): array
    {
        return ['tag_id' => $assignment->tag_id, 'weight' => $assignment->weight, 'source' => $assignment->source,
            'metadata' => $assignment->metadata, 'expires_at' => $assignment->expires_at?->format('Y-m-d H:i:s')];
    }

    public function assignments(TagSpace $space, Model $subject, array $before, array $after): void
    {
        $this->assignmentDelta($space, $subject->getMorphClass(), (string) $subject->getKey(), $before, $after);
    }

    public function assignmentDelta(TagSpace $space, string $subjectType, string $subjectKey, array $before, array $after): void
    {
        $identity = ['type' => $subjectType, 'key' => $subjectKey];
        $attached = array_values(array_diff_key($after, $before));
        $detached = array_values(array_diff_key($before, $after));
        $updated = [];
        foreach (array_intersect_key($after, $before) as $id => $row) {
            if ($row != $before[$id]) {
                $updated[] = ['before' => $before[$id], 'after' => $row];
            }
        }
        if (! $attached && ! $detached && ! $updated) {
            return;
        }
        foreach ($attached as $row) {
            $this->persist($space, 'assignment.attached', $row['tag_id'], null, $row, null, $identity);
        }
        foreach ($detached as $row) {
            $this->persist($space, 'assignment.detached', $row['tag_id'], $row, null, null, $identity);
        }
        foreach ($updated as $row) {
            $this->persist($space, 'assignment.updated', $row['after']['tag_id'], $row['before'], $row['after'], null, $identity);
        }
        event(new AssignmentsChanged($subjectType, $subjectKey, $space->tenant, $space->taxonomy, $space->context, $attached, $detached, $updated, $space->actorId, $space->operationId));
    }

    public function vocabulary(TagSpace $space, string $action, int $tagId, ?array $before, ?array $after): void
    {
        if ($before == $after) {
            return;
        }
        $this->persist($space, 'vocabulary.'.$action, $tagId, $before, $after);
        event(new VocabularyChanged($action, $tagId, $space->tenant, $space->taxonomy, $before, $after, $space->actorId, $space->operationId));
    }

    public function persist(TagSpace $space, string $kind, ?int $tagId, ?array $before, ?array $after, ?Model $subject = null, ?array $identity = null): void
    {
        if (! config('tagging.provenance.enabled', false)) {
            return;
        }
        DB::table('tagging_changes')->insert([
            'tenant' => $space->tenant,
            'taxonomy' => $space->taxonomy,
            'context' => $subject || $identity ? $space->context : null,
            'kind' => $kind,
            'tag_id' => $tagId,
            'taggable_type' => $subject?->getMorphClass() ?? $identity['type'] ?? null,
            'taggable_id' => $subject ? (string) $subject->getKey() : ($identity['key'] ?? null),
            'actor_id' => $space->actorId,
            'operation_id' => $space->operationId,
            'before' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
            'after' => $after === null ? null : json_encode($after, JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }
}

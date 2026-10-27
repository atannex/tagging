<?php

namespace Atannex\Tagging\Vocabulary;

use Atannex\Tagging\Models\Assignment;
use Atannex\Tagging\Operations\OperationalDiagnostics;
use Atannex\Tagging\TagSpace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Read-only integrity diagnostics; never infers or queries subject models. */
final class VocabularyHealth
{
    public function __construct(private readonly TagSpace $space) {}

    public function inspect(): array
    {
        $operational = (new OperationalDiagnostics)->inspect($this->space);
        if (! Schema::hasTable('tagging_tags') || ! Schema::hasTable('tagging_names') || ! Schema::hasTable('tagging_assignments')) {
            return ['healthy' => false, 'tag_count' => 0, 'issues' => $operational, 'unused_tag_ids' => [], 'inactive_assignment_count' => 0, 'expired_assignment_count' => 0];
        }
        $tags = $this->space->query()->get()->keyBy('id');
        $names = DB::table('tagging_names')->where(function ($q) use ($tags) {
            $q->where(fn ($q) => $q->where('tenant', $this->space->tenant)->where('taxonomy', $this->space->taxonomy))
                ->orWhereIn('tag_id', $tags->modelKeys());
        })->get();
        $canonical = [];
        $issues = $operational;
        foreach ($names as $name) {
            $tag = $tags->get($name->tag_id);
            if (! $tag || $name->tenant !== $this->space->tenant || $name->taxonomy !== $this->space->taxonomy) {
                $issues[] = ['code' => 'name_scope_mismatch', 'tag_id' => $name->tag_id, 'name_id' => $name->id];

                continue;
            }
            if ($name->slug === $tag->slug) {
                $canonical[$tag->id] = true;
            }
        }
        $completed = [];
        foreach ($tags as $tag) {
            if (! isset($canonical[$tag->id])) {
                $issues[] = ['code' => 'missing_canonical_name', 'tag_id' => $tag->id];
            }
            if ($tag->parent_id !== null && ! $tags->has($tag->parent_id)) {
                $issues[] = ['code' => 'invalid_parent_scope', 'tag_id' => $tag->id];
            }
            // Visit each node once across paths, emitting one issue per cycle.
            $path = [];
            $cursor = $tag;
            while ($cursor && ! isset($completed[$cursor->id])) {
                if (isset($path[$cursor->id])) {
                    $issues[] = ['code' => 'hierarchy_cycle', 'tag_id' => $cursor->id];
                    break;
                }
                $path[$cursor->id] = true;
                $cursor = $tags->get($cursor->parent_id);
            }
            foreach ($path as $id => $_) {
                $completed[$id] = true;
            }
        }
        $assignments = Assignment::query()->whereIn('tag_id', $tags->modelKeys())->where('context', $this->space->context);
        $used = (clone $assignments)->current()->distinct()->pluck('tag_id')->all();

        return [
            'healthy' => $issues === [],
            'tag_count' => $tags->count(),
            'issues' => $issues,
            'unused_tag_ids' => array_values(array_diff($tags->modelKeys(), $used)),
            'inactive_assignment_count' => (clone $assignments)->current()->whereHas('tag', fn (Builder $q) => $q->where('active', false))->count(),
            'expired_assignment_count' => (clone $assignments)->whereNotNull('expires_at')->where('expires_at', '<=', now())->count(),
        ];
    }
}

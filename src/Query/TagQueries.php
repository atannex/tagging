<?php

namespace Atannex\Tagging\Query;

use Atannex\Tagging\Models\Assignment;
use Atannex\Tagging\Models\Tag;
use Atannex\Tagging\TagSpace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Read-only queries. Tag resolution never creates vocabulary. */
final class TagQueries
{
    public function __construct(private readonly TagSpace $space) {}

    public function matching(Builder $query, iterable $tags, string $mode = 'any', ?float $minWeight = null, ?string $source = null, bool $descendants = false, ?float $maxWeight = null, bool $ancestors = false, bool $includeRoot = true, string $expiration = 'current'): Builder
    {
        $query = $this->space->scopeSubjects($query);
        if ($query->getModel()->getConnection()->getName() !== (new Tag)->getConnection()->getName()) {
            throw new InvalidArgumentException('Matching queries must use the tagging database connection.');
        }
        if (! in_array($mode, ['any', 'all', 'none'], true)) {
            throw new InvalidArgumentException('Unknown matching mode.');
        }
        if ($minWeight !== null && (! is_finite($minWeight) || $minWeight < 0)) {
            throw new InvalidArgumentException('Minimum weight must be finite and non-negative.');
        }
        $assignmentFilter = new AssignmentFilter($minWeight, $maxWeight, $source, $expiration);
        $groups = [];
        $missing = false;
        foreach ($tags as $input) {
            $tag = $this->space->find($input);
            if (! $tag || ! $tag->active) {
                $missing = true;

                continue;
            }
            if (isset($groups[$tag->id])) {
                continue;
            }
            $groups[$tag->id] = $includeRoot ? [$tag->id] : [];
            if ($descendants) {
                $groups[$tag->id] = array_merge($groups[$tag->id], $this->space->descendants($tag)->where('active', true)->modelKeys());
            }
            if ($ancestors) {
                $groups[$tag->id] = array_merge($groups[$tag->id], $this->space->ancestors($tag)->where('active', true)->modelKeys());
            }
            $groups[$tag->id] = array_values(array_unique($groups[$tag->id]));
        }
        $filter = fn (array $ids) => fn (Builder $q) => $assignmentFilter->apply($q)->where('context', $this->space->context)->whereIn('tag_id', $ids);
        if ($mode === 'all') {
            if ($missing) {
                return $query->whereRaw('1 = 0');
            }
            // Each requested category is satisfied independently, including overlapping subtrees.
            foreach ($groups as $ids) {
                $query->whereHas('tagAssignments', $filter($ids));
            }

            return $query;
        }
        $ids = array_values(array_unique(array_merge([], ...array_values($groups))));

        return $mode === 'none' ? $query->whereDoesntHave('tagAssignments', $filter($ids)) : $query->whereHas('tagAssignments', $filter($ids));
    }

    /** Tag counts over distinct subjects in an application-filtered candidate query. */
    public function facets(Builder $candidates, int $limit = 20, ?float $minWeight = null, ?string $source = null, bool $includeUnused = false): Collection
    {
        $candidates = $this->space->scopeSubjects($candidates);
        $model = $candidates->getModel();
        if ($model->getConnection()->getName() !== (new Tag)->getConnection()->getName()) {
            throw new InvalidArgumentException('Facet candidates must use the tagging connection.');
        }
        if ($minWeight !== null && (! is_finite($minWeight) || $minWeight < 0)) {
            throw new InvalidArgumentException('Minimum weight must be finite and non-negative.');
        }
        $subjects = (clone $candidates)->applyScopes()->withoutGlobalScopes();
        $base = $subjects->getQuery();
        if ($base->from !== $model->getTable() || $base->groups || $base->havings || $base->unions) {
            throw new InvalidArgumentException('Facets need an unaliased model query without grouping, having, or unions.');
        }
        $connection = $model->getConnection();
        $key = $connection->getQueryGrammar()->wrap($model->getQualifiedKeyName());
        $cast = in_array($connection->getDriverName(), ['mysql', 'mariadb'], true) ? 'CHAR(64)' : 'VARCHAR(64)';
        $subjects->select([])->selectRaw('CAST('.$key.' AS '.$cast.') AS subject_key')->distinct()->reorder();
        // Facets describe the whole filtered result, not its current page.
        $subjects->getQuery()->limit = null;
        $subjects->getQuery()->offset = null;
        $counts = Assignment::query()->current()->where('taggable_type', $model->getMorphClass())
            ->where('context', $this->space->context)
            ->joinSub($subjects, 'facet_subjects', 'tagging_assignments.taggable_id', '=', 'facet_subjects.subject_key')
            ->when($minWeight !== null, fn (Builder $q) => $q->where('weight', '>=', $minWeight))
            ->when($source !== null, fn (Builder $q) => $q->where('source', $source))
            ->select('tagging_assignments.tag_id')->selectRaw('COUNT(*) as facet_count, SUM(weight) as total_weight')
            ->groupBy('tagging_assignments.tag_id');
        $query = $this->space->query()->where('active', true);
        if ($includeUnused) {
            $query->leftJoinSub($counts, 'facet_scores', 'tagging_tags.id', '=', 'facet_scores.tag_id');
        } else {
            $query->joinSub($counts, 'facet_scores', 'tagging_tags.id', '=', 'facet_scores.tag_id');
        }

        return $query->select('tagging_tags.*')
            ->selectRaw('COALESCE(facet_scores.facet_count, 0) as facet_count, COALESCE(facet_scores.total_weight, 0) as total_weight')
            ->orderByDesc('facet_count')->orderByDesc('total_weight')->orderBy('tagging_tags.id')
            ->limit(max(1, min($limit, 100)))->get();
    }

    /** Shared-tag ranking restricted by the caller's candidate query, including global scopes. */
    public function similar(Model $subject, Builder $candidates, int $limit = 10, int $minShared = 1): Collection
    {
        if ($minShared < 1) {
            throw new InvalidArgumentException('Minimum shared tags must be at least one.');
        }
        $candidates = $this->space->scopeSubjects($candidates);
        $model = $candidates->getModel();
        if ($model->getConnection()->getName() !== $subject->getConnection()->getName()) {
            throw new InvalidArgumentException('Candidates must use the same connection as the subject.');
        }
        $seed = $this->space->assignments($subject)->current()
            ->whereHas('tag', fn (Builder $q) => $q->where('active', true))
            ->select(['tag_id', 'weight']);
        $scores = Assignment::query()->current()
            ->where('taggable_type', $model->getMorphClass())->where('context', $this->space->context)
            ->joinSub($seed, 'seed_tags', 'tagging_assignments.tag_id', '=', 'seed_tags.tag_id')
            ->select('tagging_assignments.taggable_id')
            ->selectRaw('COUNT(*) as shared_tags, SUM(tagging_assignments.weight * seed_tags.weight) as similarity_weight')
            ->groupBy('tagging_assignments.taggable_id')->havingRaw('COUNT(*) >= ?', [$minShared]);
        if ($subject->getMorphClass() === $model->getMorphClass()) {
            $scores->where('tagging_assignments.taggable_id', '!=', (string) $subject->getKey());
        }
        $query = clone $candidates;
        $connection = $model->getConnection();
        $key = $connection->getQueryGrammar()->wrap($model->getQualifiedKeyName());
        // Assignment keys are strings; PostgreSQL requires an explicit cast for integer model keys.
        $cast = in_array($connection->getDriverName(), ['mysql', 'mariadb'], true) ? 'CHAR(64)' : 'VARCHAR(64)';
        if ($query->getQuery()->columns === null) {
            $query->select($model->qualifyColumn('*'));
        }

        return $query->joinSub($scores, 'tagging_similarity', DB::raw('CAST('.$key.' AS '.$cast.')'), '=', 'tagging_similarity.taggable_id')
            ->addSelect(['tagging_similarity.shared_tags', 'tagging_similarity.similarity_weight'])
            ->reorder()->orderByDesc('tagging_similarity.shared_tags')->orderByDesc('tagging_similarity.similarity_weight')
            ->orderBy($model->getQualifiedKeyName())->limit(max(1, min($limit, 100)))->get();
    }
}

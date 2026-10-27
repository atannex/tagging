<?php

namespace Atannex\Tagging\Vocabulary;

use Atannex\Tagging\Events\DecisionChanged;
use Atannex\Tagging\Exceptions\HierarchyConflict;
use Atannex\Tagging\Exceptions\PolicyRejected;
use Atannex\Tagging\History\IncomingAssignment;
use Atannex\Tagging\History\Recorder;
use Atannex\Tagging\History\ReviewService;
use Atannex\Tagging\Models\Assignment;
use Atannex\Tagging\Models\Tag;
use Atannex\Tagging\Operations\Maintenance;
use Atannex\Tagging\Support\SpaceTransactions;
use Atannex\Tagging\TagSpace;
use Atannex\Tagging\Taxonomy\Definition;
use Atannex\Tagging\Taxonomy\StateValidator;
use Atannex\Tagging\Tenancy\ScopeGuard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;

final class VocabularyLifecycle
{
    public function __construct(private readonly TagSpace $space) {}

    public function merge(Tag $source, Tag $target): Tag
    {
        return app(SpaceTransactions::class)->run($this->space, function () use ($source, $target) {
            $source = $this->space->find($source) ?? throw new HierarchyConflict('Source tag no longer exists.');
            $target = $this->space->find($target) ?? throw new HierarchyConflict('Target tag no longer exists.');
            if ($source->is($target)) {
                return $target;
            }
            if ($this->space->descendants($source)->contains('id', $target->id)) {
                throw new HierarchyConflict('Cannot merge a tag into its descendant.');
            }
            $before = $source->toArray();
            $before['aliases'] = $this->space->aliases($source);
            // Target decisions win conflicts, just like target assignment values.
            DB::table('tagging_decisions')->where('tag_id', $source->id)->orderBy('id')->chunkById(200, function ($rows) use ($target) {
                foreach ($rows as $row) {
                    $values = (array) $row;
                    unset($values['id']);
                    $values['tag_id'] = $target->id;
                    if (DB::table('tagging_decisions')->insertOrIgnore($values)) {
                        $selected = new TagSpace($this->space->tenant, $this->space->taxonomy, $row->context, true, $this->space->actorId, $this->space->operationId);
                        $after = ['decision' => $row->decision, 'actor_id' => $row->actor_id, 'operation_id' => $row->operation_id, 'from_tag_id' => $row->tag_id];
                        app(Recorder::class)->persist($selected, 'review.merged', $target->id, null, $after, null, ['type' => $row->taggable_type, 'key' => (string) $row->taggable_id]);
                        event(new DecisionChanged($row->taggable_type, (string) $row->taggable_id, $target->id, $selected->tenant, $selected->taxonomy, $selected->context, null, $after));
                    }
                }
            });
            DB::table('tagging_names')->where('tag_id', $source->id)->update(['tag_id' => $target->id]);
            foreach ([$source->slug, ...$before['aliases']] as $alias) {
                app(Recorder::class)->vocabulary($this->space, 'alias_added', $target->id, null, ['alias' => $alias, 'from_tag_id' => $source->id]);
            }

            $source->assignments()->orderBy('id')->chunkById(200, function ($rows) use ($target) {
                foreach ($rows as $row) {
                    $class = Relation::getMorphedModel($row->taggable_type) ?? $row->taggable_type;
                    $subject = is_subclass_of($class, Model::class) ? (new $class)->newQuery()->find($row->taggable_id) : null;
                    $space = new TagSpace($this->space->tenant, $this->space->taxonomy, $row->context, true, $this->space->actorId, $this->space->operationId);
                    if ($subject) {
                        $space->transaction($subject, function () use ($space, $subject, $row, $target) {
                            $exists = $space->assignments($subject)->where('tag_id', $target->id)->exists();
                            $row->delete();
                            if (! $exists) {
                                $attributes = ['weight' => $row->weight, 'source' => $row->source, 'metadata' => $row->metadata, 'expires_at' => $row->expires_at];
                                app(IncomingAssignment::class)->validate($space, $subject, $target->id, null, $attributes);
                                $values = $row->getAttributes();
                                unset($values['id']);
                                $values['tag_id'] = $target->id;
                                DB::table('tagging_assignments')->insert($values);
                            }
                        });
                    } else {
                        $actual = is_subclass_of($class, Model::class) ? (new $class)->newQueryWithoutScopes()->find($row->taggable_id) : null;
                        $existing = Assignment::query()->where('tag_id', $target->id)->where('taggable_type', $row->taggable_type)
                            ->where('taggable_id', $row->taggable_id)->where('context', $row->context)->first();
                        if (! $existing && $actual) {
                            app(ScopeGuard::class)->subject($space, $actual);
                            app(IncomingAssignment::class)->validate($space, $actual, $target->id, null,
                                ['weight' => $row->weight, 'source' => $row->source, 'metadata' => $row->metadata, 'expires_at' => $row->expires_at]);
                        } elseif (! $actual && (config('tagging.source_policy') || config('tagging.subject_access'))) {
                            throw new PolicyRejected('Cannot apply source/access policies to a missing subject.');
                        }
                        $recorder = app(Recorder::class);
                        $beforeRows = [$row->tag_id => $recorder->assignment($row)];
                        if ($existing) {
                            $beforeRows[$existing->tag_id] = $recorder->assignment($existing);
                        }
                        $values = $row->getAttributes();
                        unset($values['id']);
                        $values['tag_id'] = $target->id;
                        DB::table('tagging_assignments')->insertOrIgnore($values);
                        $row->delete();
                        $after = Assignment::query()->where('tag_id', $target->id)->where('taggable_type', $row->taggable_type)
                            ->where('taggable_id', $row->taggable_id)->where('context', $row->context)->firstOrFail();
                        $recorder->assignmentDelta($space, $row->taggable_type, (string) $row->taggable_id, $beforeRows, [$target->id => $recorder->assignment($after)]);
                    }
                }
            });
            DB::table('tagging_names')->where('tag_id', $source->id)->update(['tag_id' => $target->id]);
            $source->children()->orderBy('id')->chunkById(200, function ($children) use ($target) {
                foreach ($children as $child) {
                    $previous = $child->toArray();
                    $child->update(['parent_id' => $target->id]);
                    app(Recorder::class)->vocabulary($this->space, 'moved', $child->id, $previous, $child->fresh()->toArray());
                }
            });
            app(ReviewService::class)->remove(DB::table('tagging_decisions')->where('tag_id', $source->id), 'merged', $this->space);
            $source->delete();
            app(StateValidator::class)->space($this->space);
            app(Recorder::class)->vocabulary($this->space, 'merged', $source->id, $before, ['target_id' => $target->id, 'transferred_aliases' => [$source->slug, ...$before['aliases']]]);

            return $target->refresh();
        });
    }

    public function delete(Tag $tag, ?string $children = null, ?string $assignments = null): void
    {
        app(SpaceTransactions::class)->run($this->space, function () use ($tag, $children, $assignments) {
            $tag = $this->space->find($tag) ?? throw new HierarchyConflict('Tag no longer exists.');
            $definition = Definition::for($this->space);
            $children ??= $definition->options['delete_children'];
            $assignments ??= $definition->options['delete_assignments'];
            if (! in_array($children, ['restrict', 'promote', 'cascade'], true) || ! in_array($assignments, ['restrict', 'cascade'], true)) {
                throw new PolicyRejected('Invalid deletion policy.');
            }
            if ($children === 'restrict' && $tag->children()->exists()) {
                throw new HierarchyConflict('Tag has children; choose promote or cascade explicitly.');
            }
            $ids = [$tag->id];
            if ($children === 'cascade') {
                $ids = array_merge($ids, $this->space->descendants($tag)->modelKeys());
            }
            if ($assignments === 'restrict' && DB::table('tagging_assignments')->whereIn('tag_id', $ids)->exists()) {
                throw new PolicyRejected('Tag has assignments; deletion is restricted.');
            }
            if ($children === 'promote') {
                $tag->children()->orderBy('id')->chunkById(200, function ($nodes) use ($tag) {
                    foreach ($nodes as $child) {
                        $previous = $child->toArray();
                        $child->update(['parent_id' => $tag->parent_id]);
                        app(Recorder::class)->vocabulary($this->space, 'moved', $child->id, $previous, $child->fresh()->toArray());
                    }
                });
            }
            app(Maintenance::class)->removeAssignments(DB::table('tagging_assignments')->whereIn('tag_id', $ids), 'vocabulary_delete', $this->space);
            foreach ($this->space->query()->whereIn('id', $ids)->orderByDesc('id')->get() as $node) {
                $before = $node->toArray();
                $before['aliases'] = $this->space->aliases($node);
                DB::table('tagging_names')->where('tag_id', $node->id)->delete();
                app(ReviewService::class)->remove(DB::table('tagging_decisions')->where('tag_id', $node->id), 'vocabulary_delete', $this->space);
                $node->delete();
                app(Recorder::class)->vocabulary($this->space, 'deleted', $node->id, $before, null);
            }
            app(StateValidator::class)->space($this->space);
        });
    }
}

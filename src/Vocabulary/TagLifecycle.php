<?php

namespace Atannex\Tagging\Vocabulary;

use Atannex\Tagging\Events\TagStatusChanged;
use Atannex\Tagging\History\Recorder;
use Atannex\Tagging\Models\Tag;
use Atannex\Tagging\Support\SpaceTransactions;
use Atannex\Tagging\TagSpace;
use InvalidArgumentException;

final class TagLifecycle
{
    public function __construct(private readonly TagSpace $space) {}

    /** Vocabulary-wide change across contexts, retaining assignment history. */
    public function setActive(Tag $tag, bool $active, bool $descendants = false): array
    {
        if (! $this->space->find($tag)) {
            throw new InvalidArgumentException('Tag no longer exists.');
        }
        $changed = app(SpaceTransactions::class)->run($this->space, function () use ($tag, $active, $descendants) {
            if ($descendants) {
                $this->space->query()->orderBy('id')->lockForUpdate()->get();
            }
            $fresh = $this->space->query()->lockForUpdate()->findOrFail($tag->id);
            $ids = [$fresh->id];
            if ($descendants) {
                $ids = array_merge($ids, $this->space->descendants($fresh)->modelKeys());
            }
            $changed = [];
            foreach ($this->space->query()->whereIn('id', $ids)->orderBy('id')->get() as $node) {
                if ($node->active === $active) {
                    continue;
                }
                $before = $node->toArray();
                $previous = $node->active;
                $node->update(['active' => $active]);
                $changed[] = $node->id;
                app(Recorder::class)->vocabulary($this->space, $active ? 'activated' : 'deactivated', $node->id, $before, $node->fresh()->toArray());
                event(new TagStatusChanged($node->id, $this->space->tenant, $this->space->taxonomy, $previous, $active));
            }

            return $changed;
        });
        $tag->refresh();

        return $changed;
    }
}

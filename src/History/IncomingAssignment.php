<?php

namespace Atannex\Tagging\History;

use Atannex\Tagging\Contracts\SourcePolicy;
use Atannex\Tagging\Exceptions\InvalidConfiguration;
use Atannex\Tagging\Exceptions\PolicyRejected;
use Atannex\Tagging\Models\Assignment;
use Atannex\Tagging\TagSpace;
use Atannex\Tagging\Taxonomy\Definition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class IncomingAssignment
{
    public function validate(TagSpace $space, Model $subject, int $tagId, ?Assignment $existing, array $attributes): void
    {
        Definition::for($space)->metadata($space, array_key_exists('metadata', $attributes) ? $attributes['metadata'] : $existing?->metadata, true);
        $policy = config('tagging.source_policy');
        if (! $policy) {
            return;
        }
        if (! is_string($policy) || ! is_a($policy, SourcePolicy::class, true)) {
            throw new InvalidConfiguration('source_policy must implement SourcePolicy.');
        }
        $decision = config('tagging.review_tracking', false) ? DB::table('tagging_decisions')->where('tag_id', $tagId)
            ->where('taggable_type', $subject->getMorphClass())->where('taggable_id', (string) $subject->getKey())->where('context', $space->context)->first() : null;
        if (! app($policy)->allows($space, $subject, $existing, $attributes, $decision ? (array) $decision : null)) {
            throw new PolicyRejected('Incoming source is not permitted to replace this assignment.');
        }
    }
}

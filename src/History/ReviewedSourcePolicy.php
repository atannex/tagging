<?php

namespace Atannex\Tagging\History;

use Atannex\Tagging\Contracts\SourcePolicy;
use Atannex\Tagging\Models\Assignment;
use Atannex\Tagging\TagSpace;
use Illuminate\Database\Eloquent\Model;

final class ReviewedSourcePolicy implements SourcePolicy
{
    public function allows(TagSpace $space, Model $subject, ?Assignment $existing, array $incoming, ?array $decision): bool
    {
        $source = $incoming['source'] ?? $existing->source ?? 'manual';
        if ($source === 'manual') {
            return true;
        }
        if (($decision['decision'] ?? null) === 'rejected') {
            return false;
        }

        return ! (($decision['decision'] ?? null) === 'approved' && $existing?->source === 'manual');
    }
}

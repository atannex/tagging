<?php

namespace Atannex\Tagging\Vocabulary;

use Atannex\Tagging\TagSpace;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LengthException;

/** Bounded review suggestions, never automatic identity changes. */
final class DuplicateFinder
{
    public function __construct(private readonly TagSpace $space) {}

    public function find(int $maxDistance = 1, int $limit = 20, bool $includeInactive = false): array
    {
        if ($maxDistance < 0 || $maxDistance > 3) {
            throw new InvalidArgumentException('Duplicate distance must be between zero and three.');
        }
        $maximum = config('tagging.max_duplicate_scan_tags', 300);
        if (! is_int($maximum) || $maximum < 1) {
            throw new InvalidArgumentException('Duplicate scan limit must be a positive integer.');
        }
        $tags = $this->space->query()->when(! $includeInactive, fn ($q) => $q->where('active', true))
            ->orderBy('id')->limit($maximum + 1)->get();
        if ($tags->count() > $maximum) {
            throw new LengthException('Vocabulary exceeds the configured duplicate scan limit.');
        }
        $rows = [];
        foreach ($tags as $tag) {
            $folded = implode('', array_map(fn ($character) => Str::ascii($character) ?: $character, mb_str_split($tag->name)));
            $normalized = preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($folded));
            // Punctuation-only labels do not provide enough evidence for similarity.
            if ($normalized === '') {
                continue;
            }
            $rows[] = ['tag' => ['id' => $tag->id, 'name' => $tag->name, 'slug' => $tag->slug], 'characters' => mb_str_split($normalized)];
        }
        $pairs = [];
        foreach ($rows as $i => $left) {
            for ($j = $i + 1; $j < count($rows); $j++) {
                $right = $rows[$j];
                if (abs(count($left['characters']) - count($right['characters'])) > $maxDistance) {
                    continue;
                }
                $distance = $this->distance($left['characters'], $right['characters'], $maxDistance);
                if ($distance <= $maxDistance) {
                    $pairs[] = ['left' => $left['tag'], 'right' => $right['tag'], 'distance' => $distance];
                }
            }
        }
        usort($pairs, fn ($a, $b) => [$a['distance'], $a['left']['id'], $a['right']['id']] <=> [$b['distance'], $b['left']['id'], $b['right']['id']]);

        return array_slice($pairs, 0, max(1, min($limit, 100)));
    }

    /** Unicode code-point edit distance, abandoning paths that exceed the requested bound. */
    private function distance(array $left, array $right, int $maximum): int
    {

        $width = count($right);
        $infinity = $maximum + 1;
        $previous = array_fill_keys(range(0, min($width, $maximum)), 0);
        foreach ($previous as $j => $_) {
            $previous[$j] = $j;
        }
        foreach ($left as $i => $character) {
            $row = $i + 1;
            $current = $row <= $maximum ? [0 => $row] : [];
            for ($column = max(1, $row - $maximum); $column <= min($width, $row + $maximum); $column++) {
                $current[$column] = min(
                    ($current[$column - 1] ?? $infinity) + 1,
                    ($previous[$column] ?? $infinity) + 1,
                    ($previous[$column - 1] ?? $infinity) + ($character === $right[$column - 1] ? 0 : 1),
                );
            }
            if (! $current || min($current) > $maximum) {
                return $infinity;
            }
            $previous = $current;
        }

        return $previous[$width] ?? $infinity;
    }
}

<?php

namespace Atannex\Tagging\Automation;

use Atannex\Tagging\Support\Inputs;
use Atannex\Tagging\TagSpace;
use Atannex\Tagging\Tenancy\ScopeGuard;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

final class AutoTagger
{
    public function __construct(private readonly TagSpace $space, private readonly string $source = 'rules:default')
    {
        if (! str_starts_with($source, 'rules:') || strlen($source) <= 6 || strlen($source) > 64) {
            throw new InvalidArgumentException('Automation source must start with rules: and contain a nonempty identifier, at most 64 bytes total.');
        }
    }

    /** Evaluate application-owned callbacks without creating tags or assignments. */
    public function preview(Model $subject, iterable $rules): array
    {
        app(ScopeGuard::class)->subject($this->space, $subject);
        $matches = [];
        $names = [];
        $count = 0;
        $tagCount = 0;
        foreach ($rules as $rule) {
            if (! $rule instanceof Rule || ++$count > config('tagging.max_automation_rules', 100)) {
                throw new InvalidArgumentException('Invalid rule or too many automation rules.');
            }
            if (isset($names[$rule->name])) {
                throw new InvalidArgumentException('Rule names must be unique.');
            }
            $names[$rule->name] = true;
            if ($rule->matches($subject)) {
                $tagCount += count($rule->tags);
                if ($tagCount > config('tagging.max_tags_per_operation', 100)) {
                    throw new InvalidArgumentException('Too many matching rule tags.');
                }
                $matches[] = ['rule' => $rule->name, 'tags' => $rule->tags, 'weight' => $rule->weight];
            }
        }

        return $matches;
    }

    /** Synchronize only this automation source, preserving assignments owned by other sources. */
    public function apply(Model $subject, iterable $rules, bool $replace = true): array
    {
        $rules = Inputs::bounded($rules, 'max_automation_rules');
        // Validate saved state and database connection through the same path as all mutations.
        $this->space->assignments($subject);

        return $this->space->transaction($subject, function () use ($subject, $rules, $replace) {
            $subject->newQuery()->whereKey($subject->getKey())->lockForUpdate()->firstOrFail();
            $matches = $this->preview($subject, $rules);
            $resolved = [];
            foreach ($matches as $match) {
                foreach ($match['tags'] as $name) {
                    $tag = $this->space->assignmentTag($name);
                    if (! $tag->active) {
                        throw new InvalidArgumentException('A matching automation tag is inactive.');
                    }
                    $entry = $resolved[$tag->id] ?? ['tag' => $tag, 'weight' => 0.0, 'rules' => []];
                    $entry['weight'] = max($entry['weight'], $match['weight']);
                    $entry['rules'][] = $match['rule'];
                    $resolved[$tag->id] = $entry;
                }
            }
            $existing = $this->space->assignments($subject)->get()->keyBy('tag_id');
            $attached = [];
            $preserved = [];
            $detached = [];
            foreach ($resolved as $id => $entry) {
                if (isset($existing[$id]) && $existing[$id]->source !== $this->source) {
                    $preserved[] = $id;

                    continue;
                }
                $this->space->attach($subject, [$entry['tag']], [
                    'source' => $this->source, 'weight' => $entry['weight'], 'expires_at' => null,
                    'metadata' => ['rules' => array_values(array_unique($entry['rules']))],
                ]);
                $attached[] = $id;
            }
            if ($replace) {
                $detached = $existing->filter(fn ($a) => $a->source === $this->source && ! isset($resolved[$a->tag_id]))->pluck('tag_id')->all();
                if ($detached) {
                    $this->space->detach($subject, $this->space->query()->whereIn('id', $detached)->get());
                }
            }

            return ['matched_rules' => array_column($matches, 'rule'), 'attached' => $attached, 'detached' => $detached, 'preserved' => $preserved];
        });
    }
}

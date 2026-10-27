<?php

namespace Atannex\Tagging\Vocabulary;

use Atannex\Tagging\Exceptions\ImportFailure;
use Atannex\Tagging\Governance\PolicyViolation;
use Atannex\Tagging\History\Recorder;
use Atannex\Tagging\Models\Tag;
use Atannex\Tagging\Support\SpaceTransactions;
use Atannex\Tagging\TagSpace;
use Atannex\Tagging\Taxonomy\Definition;
use Atannex\Tagging\Taxonomy\StateValidator;
use RuntimeException;

/** A size-bounded document is applied in one namespace transaction; no partial import is reported. */
final class VocabularyTransfer
{
    public function __construct(private readonly TagSpace $space) {}

    public function import(array $document, bool $dryRun = false, string $conflict = 'update'): array
    {
        if (! $this->space->tenantSelected) {
            throw new ImportFailure('Choose an explicit destination space, including an explicit empty tenant when intended.');
        }
        if (! in_array($conflict, ['update', 'keep', 'fail'], true)) {
            throw new ImportFailure('Conflict policy must be update, keep, or fail.');
        }
        try {
            $rows = $this->validate($document);

            return app(SpaceTransactions::class)->run($this->space, function () use ($rows, $document, $dryRun, $conflict) {
                $run = function () use ($rows, $document, $dryRun, $conflict) {
                    $tags = [];
                    $before = [];
                    $skipped = [];
                    $created = 0;
                    $updated = 0;
                    foreach ($rows as $row) {
                        $existing = $this->space->find($row['name']);
                        if ($existing && $existing->slug !== $row['slug']) {
                            throw new ImportFailure('Import name collides with an existing alias: '.$row['name']);
                        }
                        if ($existing && $conflict === 'fail') {
                            throw new ImportFailure('Canonical tag already exists: '.$row['slug']);
                        }
                        if ($existing && $conflict === 'keep') {
                            $skipped[$row['slug']] = true;
                            $tags[$row['slug']] = $existing;

                            continue;
                        }
                        $attributes = ['name' => trim($row['name']), 'metadata' => $row['metadata'], 'translations' => $row['translations'], 'active' => $row['active']];
                        $tag = $existing ?? $this->space->create($row['name'], $attributes);
                        $before[$row['slug']] = $existing?->toArray();
                        if (($document['version'] ?? 1) === 2) {
                            $attributes['approved_at'] = $row['approved'] ? ($tag->approved_at ?? now()) : null;
                        }
                        $tag->update($attributes);
                        $tags[$row['slug']] = $tag;
                        $existing ? $updated++ : $created++;
                    }
                    foreach ($rows as $row) {
                        if (isset($skipped[$row['slug']])) {
                            continue;
                        }
                        $tag = $tags[$row['slug']];
                        foreach ($row['aliases'] as $alias) {
                            $this->space->alias($tag, $alias);
                        }
                        $parent = $row['parent'] === null ? null : ($tags[$row['parent']] ?? $this->space->find($row['parent']));
                        if ($row['parent'] !== null && (! $parent || $parent->slug !== $row['parent'])) {
                            throw new ImportFailure('Missing canonical parent: '.$row['parent']);
                        }
                        // Apply the planned graph directly, then validate once; intermediate graphs need not be valid.
                        $tag->update(['parent_id' => $parent?->id]);
                    }
                    app(StateValidator::class)->space($this->space);
                    foreach ($tags as $slug => $tag) {
                        if (isset($skipped[$slug])) {
                            continue;
                        }
                        $previous = $before[$slug];
                        $after = $tag->fresh()->toArray();
                        $action = $previous && $previous['name'] !== $after['name'] ? 'renamed' : ($previous && $previous['parent_id'] !== $after['parent_id'] ? 'moved' : 'imported');
                        app(Recorder::class)->vocabulary($this->space, $action, $tag->id, $previous, $after);
                    }
                    $report = ['created' => $created, 'updated' => $updated, 'dry_run' => $dryRun];
                    if ($conflict !== 'update') {
                        $report['kept'] = count($skipped);
                    }
                    if ($dryRun) {
                        throw new ImportPreview($report);
                    }

                    return $report;
                };

                return $dryRun ? Tag::withoutEvents($run) : $run();
            });
        } catch (ImportPreview $preview) {
            return $preview->report;
        } catch (\InvalidArgumentException|PolicyViolation $exception) {
            if ($exception instanceof ImportFailure) {
                throw $exception;
            }
            throw new ImportFailure($exception->getMessage(), 0, $exception);
        }
    }

    private function validate(array $document): array
    {
        try {
            $bytes = strlen(json_encode($document, JSON_THROW_ON_ERROR));
        } catch (\JsonException $e) {
            throw new ImportFailure('Document is not valid JSON data.', 0, $e);
        }
        if ($bytes > config('tagging.max_import_bytes', 10485760)) {
            throw new ImportFailure('Vocabulary document is too large.');
        }
        if (! in_array($document['version'] ?? null, [1, 2], true) || ! is_array($document['tags'] ?? null) || ! array_is_list($document['tags'])) {
            throw new ImportFailure('Expected a version 1 or 2 vocabulary with a tags list.');
        }
        if (count($document['tags']) > config('tagging.max_import_tags', 5000)) {
            throw new ImportFailure('Vocabulary exceeds the configured import limit.');
        }
        $names = [];
        $parents = [];
        foreach ($document['tags'] as $row) {
            if (! is_array($row) || ! is_string($row['name'] ?? null) || ! is_string($row['slug'] ?? null)
                || ! array_key_exists('parent', $row) || ($row['parent'] !== null && ! is_string($row['parent']))
                || ! is_array($row['aliases'] ?? null) || ! array_is_list($row['aliases']) || ! is_array($row['metadata'] ?? null)
                || ! is_array($row['translations'] ?? null) || ! is_bool($row['active'] ?? null)
                || ($document['version'] === 2 && ! is_bool($row['approved'] ?? null))) {
                throw new ImportFailure('Invalid vocabulary row; export a document for the required schema.');
            }
            if ($this->space->normalizeName($row['name']) !== $row['slug']) {
                throw new ImportFailure('Canonical slug must match its normalized name.');
            }
            if (isset($parents[$row['slug']])) {
                throw new ImportFailure('Duplicate canonical slug in import.');
            }
            $parents[$row['slug']] = ['parent' => $row['parent']];
            if (count($row['aliases']) > config('tagging.max_import_aliases_per_tag', 100)) {
                throw new ImportFailure('Too many aliases.');
            }
            foreach ([$row['slug'], ...$row['aliases']] as $name) {
                if (! is_string($name)) {
                    throw new ImportFailure('Aliases must be strings.');
                }
                $normalized = $this->space->normalizeName($name);
                if (isset($names[$normalized]) && $names[$normalized] !== $row['slug']) {
                    throw new ImportFailure('Names collide inside the document: '.$normalized);
                }
                $names[$normalized] = $row['slug'];
            }
            Definition::for($this->space)->metadata($this->space, $row['metadata']);
            foreach ($row['translations'] as $locale => $label) {
                if (! is_string($locale) || strlen($locale) > 64 || ! is_string($label) || mb_strlen($label) > 4096) {
                    throw new ImportFailure('Translation locales and labels must be bounded strings.');
                }
            }
        }
        foreach ($parents as $slug => $_) {
            $seen = [];
            $cursor = $slug;
            while (isset($parents[$cursor])) {
                if (isset($seen[$cursor])) {
                    throw new ImportFailure('Import contains a hierarchy cycle.');
                }
                $seen[$cursor] = true;
                $cursor = $parents[$cursor]['parent'];
                if ($cursor === null) {
                    break;
                }
            }
        }

        return $document['tags'];
    }
}
final class ImportPreview extends RuntimeException
{
    public function __construct(public readonly array $report)
    {
        parent::__construct('Import preview completed.');
    }
}

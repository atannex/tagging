<?php

namespace Atannex\Tagging\Console;

use Atannex\Tagging\Exceptions\ImportFailure;
use Atannex\Tagging\Support\Settings;
use Atannex\Tagging\Tagging;
use Illuminate\Console\Command;
use InvalidArgumentException;
use JsonException;

class ImportCommand extends Command
{
    use ReportsErrors;

    protected $signature = 'tagging:import {file : Local vocabulary JSON file} {--tenant=} {--taxonomy=tags} {--dry-run} {--conflict=update}';

    protected $description = 'Import a vocabulary atomically into an explicit tenant and taxonomy';

    public function handle(Tagging $tagging): int
    {
        return $this->safely(fn () => $this->executeOperation($tagging));
    }

    protected function executeOperation(Tagging $tagging): int
    {
        Settings::validate();
        $file = $this->argument('file');
        if (! is_file($file) || ! is_readable($file)) {
            $this->error('Vocabulary file is missing or unreadable.');

            return self::FAILURE;
        }
        if (filesize($file) > config('tagging.max_import_bytes', 10485760)) {
            $this->error('Vocabulary file exceeds the configured size limit.');

            return self::FAILURE;
        }
        try {
            // Bound the actual read too: the file can grow after the size check.
            $maximum = config('tagging.max_import_bytes', 10485760);
            $contents = file_get_contents($file, false, null, 0, $maximum === PHP_INT_MAX ? $maximum : $maximum + 1);
            if ($contents === false) {
                throw new InvalidArgumentException('Unable to read vocabulary file.');
            }
            if (strlen($contents) > $maximum) {
                throw new InvalidArgumentException('Vocabulary file exceeds the configured size limit.');
            }
            $document = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($document)) {
                throw new InvalidArgumentException('Vocabulary must be a JSON object.');
            }
            if ($this->option('tenant') === null) {
                throw new ImportFailure('Import requires --tenant, including --tenant= for the explicit empty tenant.');
            }
            $report = $tagging->for((string) $this->option('tenant'), $this->option('taxonomy'))
                ->import($document, (bool) $this->option('dry-run'), $this->option('conflict'));
        } catch (JsonException|InvalidArgumentException $exception) {
            $this->error($this->safeMessage($exception));

            return self::FAILURE;
        }
        $this->info(($report['dry_run'] ? 'Preview: ' : 'Imported: ').$report['created'].' created, '.$report['updated'].' updated.');

        return self::SUCCESS;
    }
}

<?php

namespace Atannex\Tagging\Console;

use Atannex\Tagging\Tagging;
use Illuminate\Console\Command;

class ExportCommand extends Command
{
    use ReportsErrors;

    protected $signature = 'tagging:export {--tenant=} {--taxonomy=tags} {--format-version=1}';

    protected $description = 'Write a versioned vocabulary JSON document to standard output';

    public function handle(Tagging $tagging): int
    {
        return $this->safely(fn () => $this->executeOperation($tagging));
    }

    protected function executeOperation(Tagging $tagging): int
    {
        if (config('tagging.strict_tenants', false) && $this->option('tenant') === null) {
            $this->error('Strict mode requires --tenant (use --tenant= for the explicit empty tenant).');

            return self::FAILURE;
        }
        $document = $tagging->for((string) $this->option('tenant'), $this->option('taxonomy'))->export((int) $this->option('format-version'));
        $this->output->writeln(json_encode($document, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}

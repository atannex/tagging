<?php

namespace Atannex\Tagging\Console;

use Atannex\Tagging\Operations\Maintenance;
use Illuminate\Console\Command;

class PruneCommand extends Command
{
    use ReportsErrors;

    protected $signature = 'tagging:prune {--dry-run : Count without deleting} {--tenant=} {--taxonomy=} {--context=}';

    protected $description = 'Remove expired assignments in bounded batches, globally or in a selected scope';

    public function handle(Maintenance $maintenance): int
    {
        return $this->safely(fn () => $this->executeOperation($maintenance));
    }

    protected function executeOperation(Maintenance $maintenance): int
    {
        $count = $maintenance->prune($this->option('tenant'), $this->option('taxonomy'), $this->option('context'), (bool) $this->option('dry-run'));
        $this->info($count.' expired assignments '.($this->option('dry-run') ? 'found.' : 'removed.'));
        $this->line('Scope: tenant='.($this->option('tenant') ?? '*').', taxonomy='.($this->option('taxonomy') ?? '*').', context='.($this->option('context') ?? '*'));

        return self::SUCCESS;
    }
}

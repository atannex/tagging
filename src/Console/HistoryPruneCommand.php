<?php

namespace Atannex\Tagging\Console;

use Atannex\Tagging\Operations\Maintenance;
use Illuminate\Console\Command;

class HistoryPruneCommand extends Command
{
    use ReportsErrors;

    protected $signature = 'tagging:history-prune {--days=} {--tenant=} {--taxonomy=} {--dry-run}';

    protected $description = 'Apply provenance retention in bounded batches; decisions are retained';

    public function handle(Maintenance $maintenance): int
    {
        return $this->safely(fn () => $this->executeOperation($maintenance));
    }

    protected function executeOperation(Maintenance $maintenance): int
    {
        $days = $this->option('days');
        if ($days !== null && (! ctype_digit($days) || (int) $days < 1)) {
            $this->error('Days must be positive.');

            return self::FAILURE;
        }
        $count = $maintenance->history($this->option('tenant'), $this->option('taxonomy'), $days === null ? null : (int) $days, (bool) $this->option('dry-run'));
        $this->info($count.' provenance records '.($this->option('dry-run') ? 'found.' : 'removed.'));

        return self::SUCCESS;
    }
}

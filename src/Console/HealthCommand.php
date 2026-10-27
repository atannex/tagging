<?php

namespace Atannex\Tagging\Console;

use Atannex\Tagging\Tagging;
use Illuminate\Console\Command;

class HealthCommand extends Command
{
    use ReportsErrors;

    protected $signature = 'tagging:health {--tenant=} {--taxonomy=tags} {--context=default} {--json}';

    protected $description = 'Inspect vocabulary integrity and assignment hygiene without modifying data';

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
        $report = $tagging->for((string) $this->option('tenant'), $this->option('taxonomy'), $this->option('context'))->health();
        if ($this->option('json')) {
            $this->output->writeln(json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        } else {
            $this->info($report['tag_count'].' tags; '.count($report['issues']).' integrity issues.');
            $this->line(count($report['unused_tag_ids']).' unused tags; '.$report['inactive_assignment_count'].' live assignments to inactive tags; '.$report['expired_assignment_count'].' expired assignments.');
            foreach ($report['issues'] as $issue) {
                $this->line($issue['code'].(isset($issue['tag_id']) ? ' (tag '.$issue['tag_id'].')' : ' '.($issue['message'] ?? $issue['index'] ?? $issue['table'] ?? $issue['type'] ?? '')));
            }
        }

        return $report['healthy'] ? self::SUCCESS : self::FAILURE;
    }
}

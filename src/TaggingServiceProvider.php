<?php

namespace Atannex\Tagging;

use Atannex\Tagging\Console\ExportCommand;
use Atannex\Tagging\Console\HealthCommand;
use Atannex\Tagging\Console\HistoryPruneCommand;
use Atannex\Tagging\Console\ImportCommand;
use Atannex\Tagging\Console\PruneCommand;
use Atannex\Tagging\Contracts\AuditSink;
use Atannex\Tagging\Contracts\HierarchyStrategy;
use Atannex\Tagging\Events\AssignmentsChanged;
use Atannex\Tagging\Events\DecisionChanged;
use Atannex\Tagging\Events\MaintenanceCompleted;
use Atannex\Tagging\Events\VocabularyChanged;
use Atannex\Tagging\Governance\AssignmentGuard;
use Atannex\Tagging\Hierarchy\AdjacencyList;
use Atannex\Tagging\Support\SpaceTransactions;
use Illuminate\Support\ServiceProvider;

class TaggingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/tagging.php', 'tagging');
        $this->app->singleton(Tagging::class);
        $this->app->bind(HierarchyStrategy::class, AdjacencyList::class);
        $this->app->scoped(AssignmentGuard::class);
        $this->app->scoped(SpaceTransactions::class);
    }

    public function boot(): void
    {
        foreach ([AssignmentsChanged::class, VocabularyChanged::class, DecisionChanged::class, MaintenanceCompleted::class] as $event) {
            $this->app['events']->listen($event, function ($notification) {
                if ($this->app->bound(AuditSink::class)) {
                    $this->app->make(AuditSink::class)->record($notification);
                }
            });
        }
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'tagging');
        $this->publishes([__DIR__.'/../lang' => lang_path('vendor/tagging')], 'tagging-translations');
        if (config('tagging.load_migrations', true)) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }
        $this->publishes([__DIR__.'/../config/tagging.php' => config_path('tagging.php')], 'tagging-config');
        $this->publishesMigrations([__DIR__.'/../database/migrations' => database_path('migrations')], 'tagging-migrations');
        if ($this->app->runningInConsole()) {
            $this->commands([PruneCommand::class, ExportCommand::class, ImportCommand::class, HealthCommand::class, HistoryPruneCommand::class]);
        }
    }
}

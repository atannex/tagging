<?php

namespace Atannex\Tagging\Tests;

use Atannex\Tagging\TaggingServiceProvider;
use Atannex\Tagging\Tests\Support\DatabaseEnvironment;
use Orchestra\Testbench\TestCase as Testbench;

abstract class TestCase extends Testbench
{
    protected function getPackageProviders($app): array
    {
        return [TaggingServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        DatabaseEnvironment::configure($app);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->defineSubjectTables();
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    /** Create the tables for the models a test case tags. */
    protected function defineSubjectTables(): void {}
}

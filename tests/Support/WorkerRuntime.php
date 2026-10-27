<?php

namespace Atannex\Tagging\Tests\Support;

use Atannex\Tagging\TaggingServiceProvider;
use Orchestra\Testbench\TestCase;

final class WorkerRuntime extends TestCase
{
    public function worker(): void {}

    public function start(): void
    {
        parent::setUp();
    }

    protected function getPackageProviders($app): array
    {
        return [TaggingServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        DatabaseEnvironment::configure($app);
    }
}

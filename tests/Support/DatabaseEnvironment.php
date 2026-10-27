<?php

namespace Atannex\Tagging\Tests\Support;

use Illuminate\Support\Facades\Schema;

final class DatabaseEnvironment
{
    public static function configure($app): void
    {
        $driver = getenv('TAGGING_DB') ?: 'sqlite';
        if ($driver === 'sqlite') {
            return;
        }
        if (! in_array($driver, ['mysql', 'pgsql'], true) || getenv('TAGGING_DB_DATABASE') !== 'tagging') {
            throw new \RuntimeException('External suites require an explicit disposable TAGGING_DB_DATABASE=tagging.');
        }
        $app['config']->set('database.connections.testing', [
            'driver' => $driver, 'host' => getenv('TAGGING_DB_HOST') ?: '127.0.0.1',
            'port' => getenv('TAGGING_DB_PORT') ?: ($driver === 'mysql' ? 3306 : 5432),
            'database' => 'tagging', 'username' => getenv('TAGGING_DB_USERNAME') ?: ($driver === 'mysql' ? 'root' : 'postgres'),
            'password' => getenv('TAGGING_DB_PASSWORD') ?: 'tagging-test', 'charset' => $driver === 'mysql' ? 'utf8mb4' : 'utf8',
            'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'schema' => 'public', 'search_path' => 'public', 'strict' => true,
        ]);
        if (getenv('TAGGING_DB_RESET') === '1') {
            $app->booted(fn () => Schema::dropAllTables());
        }
    }
}

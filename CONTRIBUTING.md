# Contributing

Work against a supported PHP/framework profile. Install development dependencies with `composer install`; no other Atannex package is needed.

```sh
composer test
composer format
composer format:check
composer analyse
composer validate --strict --no-check-publish
```

Static analysis uses Larastan/PHPStan level 5. On environments that disallow worker TCP sockets, `vendor/bin/phpstan analyse --debug --no-progress --memory-limit=512M` runs the same analysis in-process. Formatting excludes the already-installed original schema migration; do not rewrite it.

## Real database suites

Use **disposable databases only**. The fixture helper drops all tables when `TAGGING_DB_RESET=1`, and requires the explicit database name `tagging`. It must never point at application/production data. Local sample services:

```sh
docker run -d --name tagging-mysql -e MYSQL_ROOT_PASSWORD=tagging-test -e MYSQL_DATABASE=tagging -p 127.0.0.1:13316:3306 mysql:8.4
docker run -d --name tagging-postgres -e POSTGRES_PASSWORD=tagging-test -e POSTGRES_DB=tagging -p 127.0.0.1:15436:5432 postgres:16
bash scripts/test-database mysql 13316
bash scripts/test-database pgsql 15436
```

Both scripts run the regression/migration suite and real concurrency tests. PostgreSQL's serialization-specific test is skipped on MySQL; SQLite skips all concurrency tests and is never a substitute for these checks. Tests start separate PHP processes, coordinate file barriers, and observe database lock waits through a third connection. The test database users need test-only visibility into `pg_stat_activity` / MySQL `performance_schema.data_lock_waits`.

`TAGGING_TEST_AUTOLOAD` selects an isolated compatible vendor/autoload.php; `TAGGING_PHP_BINARY` selects its PHP executable. The helper uses that vendor's PHPUnit. Example: `TAGGING_TEST_AUTOLOAD=/tmp/profile/vendor/autoload.php TAGGING_PHP_BINARY=/path/to/php bash scripts/test-database pgsql 15436`.

The [CI workflow](.github/workflows/tests.yml) runs the SQLite suite across PHP 8.2–8.5 and Laravel 11–13 (lowest and highest dependency resolutions), formatting and static analysis, and the MySQL 8.4 / PostgreSQL 16 suites on Laravel 12 and 13. `composer test:mysql` and `composer test:pgsql` wrap the database script with its default ports.

## Review expectations

Preserve existing identity, namespace, default-space, no-op, source, and raw-relationship contracts. Add an upgrade migration for schema changes. Add invariant/failure-mode tests, not tests that simply repeat implementation expressions. Any new race guarantee needs independent-process MySQL/PostgreSQL verification. Keep callbacks inside retryable transactions free of external effects. Record benchmark data, environment, duration, memory, and query counts before making performance claims.

Feature tests live in `tests/Feature` and extend `Tests\TestCase`, which boots the provider and runs the package migrations; override `defineSubjectTables()` to create the tables your fixture models use. Fixture models and contract implementations live one per file in `tests/Fixtures`; `tests/Support` holds shared runtimes, and `tests/Integration` the independent-process race tests. These are reusable examples for package tests; production code must not depend on application models. Security issues should follow [SECURITY.md](SECURITY.md), not a public issue with sensitive data.

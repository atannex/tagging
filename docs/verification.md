# Verification record

Verified October 27, 2026. These are local runs; the GitHub Actions matrix covers the remaining profiles.

| Profile | Result |
| --- | --- |
| PHP 8.5.0, Laravel 13.38.0, SQLite 3.45.2 | 139 tests, 523 assertions, 13 database-specific skips |
| PHP 8.5.0, Laravel 13.38.0, PostgreSQL 16 | 139 tests, 595 assertions, no skips |
| PHP 8.5.0, Laravel 13.38.0, MySQL 8.4 | Full run: 138 tests, 569 assertions, 3 PostgreSQL-specific skips; final import checks: 2 tests, 15 assertions |
| PHP 8.2.12, Laravel 12.70.2, SQLite | 139 tests, 523 assertions, 13 database-specific skips |
| PHP 8.2.12, Laravel 12.70.2, PostgreSQL 16 | 139 tests, 595 assertions, no skips |

PHPUnit 11.5.57 runs the primary profile. The PostgreSQL suite exercises independent-process namespace locking, alias conflicts, complete-set synchronization, merge/write races, hierarchy cycles, rollback visibility, review protection, deadlocks, statement serialization, and commit-time serialization. SQLite skips these checks; its pass is not race verification.

Other successful checks: `composer validate --strict`, `composer format:check`, PHPStan level 5 (no errors), and `composer audit --locked` (no advisories at verification time). PHPStan's optional turbo extension is unavailable on the local PHP 8.5 build; analysis still completes successfully. Sandboxed parallel analysis needed permission for local worker sockets.

Distribution archives contain only `src`, `config`, `database`, `lang`, and the top-level README, CHANGELOG, LICENSE, and SECURITY files; tests, docs, benchmarks, scripts, and tooling configuration are `export-ignore`d in `.gitattributes`.

## Reproduce

```sh
composer install
composer validate --strict
composer test
composer format:check
composer analyse
composer audit --locked
bash scripts/test-database mysql 13316
bash scripts/test-database pgsql 15436
php benchmarks/run.php 500 1000
```

External database suites require disposable services and reset all tables in the explicitly named `tagging` database; see [contributing](../CONTRIBUTING.md). Both database services were fresh local containers. The isolated Laravel 12 profile uses `TAGGING_TEST_AUTOLOAD` and `TAGGING_PHP_BINARY` as documented there.

The 500-tag / 1,000-subject / 5,000-assignment SQLite benchmark completed with descendant/popular/facet queries using one query each, matching using three, and eager loading 100 subjects using two. Observed times were 16.393 / 6.225 / 8.071 / 2.669 / 37.490 milliseconds respectively; peak process memory was 32 MiB for eager loading. This single local measurement establishes no production latency or throughput guarantee.

The full CI matrix, Laravel 11, PHP 8.3/8.4, and lowest dependency resolutions were not rerun locally. Application-specific authorization, production data upgrades, deployment configuration, and backup restoration require verification in the consuming application. Follow the [production inspection](inspection.md) and [upgrade guide](upgrading.md).

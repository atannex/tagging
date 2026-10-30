# atannex/tagging

[![Checks](https://github.com/atannex/tagging/actions/workflows/tests.yml/badge.svg)](https://github.com/atannex/tagging/actions/workflows/tests.yml)
[![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4?logo=php&logoColor=white)](composer.json)
[![Laravel](https://img.shields.io/badge/Laravel-11%20%7C%2012%20%7C%2013-FF2D20?logo=laravel&logoColor=white)](composer.json)
[![License: MIT](https://img.shields.io/badge/License-MIT-2ea44f.svg)](LICENSE)

A contextual vocabulary engine for Laravel. Attach tags, categories, skills, classifications, or editorial labels to any Eloquent model, with explicit tenant, taxonomy, and assignment-context boundaries.

Tag identity is **tenant + taxonomy + normalized slug**. Assignment identity is **tag + morph type + subject key + context**; source labels never create a second assignment identity. `TagSpace` is immutable and keeps tenant context explicit in requests, queues, and long-running workers.

It has no dependencies on other Atannex packages.

## Installation

```sh
composer require atannex/tagging
php artisan migrate
php artisan vendor:publish --tag=tagging-config
```

Provider/facade discovery is automatic. Migrations load automatically; publishing migrations is optional. Do not load both published and package migrations: set `tagging.load_migrations=false` when managing published copies.

## Quick start

```php
use Atannex\Tagging\Concerns\HasTags;
use Illuminate\Database\Eloquent\Model;

class Article extends Model
{
    use HasTags;
}

$article = Article::findOrFail($id);
$article->attachTags(['Laravel', 'Architecture']);
$article->syncTags(['PHP', 'Laravel']);
Article::withAllTags(['PHP', 'Laravel'])->paginate();
```

For an explicit space:

```php
use Atannex\Tagging\Facades\Tagging;

$topics = Tagging::for('tenant-42', 'topics', 'editorial');
$topics->attach($article, ['Architecture'], ['weight' => 2.5, 'source' => 'editor']);
$topics->tags($article);

$loaded = $topics->eagerLoad(Article::limit(50)->get());
$loaded->tags($article); // Use a model included in the loaded collection.
```

Defaults remain tenant `''`, taxonomy `tags`, context `default`. Strict tenant selection, ownership integration, taxonomy constraints, review protection, and persisted provenance are opt-in. Partitioning is not authorization: authorize the business action in your application and choose the space from trusted state.

## Compatibility and verification

- Laravel 13 requires PHP **8.3+**. Laravel 12 retains PHP **8.2+** support.
- Laravel 11 remains a **legacy compatibility line**, not a security-supported recommendation.
- Real MySQL 8.4 and PostgreSQL 16 suites use independent processes and observed database lock waits. SQLite regression tests are not presented as race verification.
- Tested on PHP 8.2–8.5 with Laravel 11–13, on SQLite, MySQL 8.4 and PostgreSQL 16.

## Security

Report vulnerabilities privately; see [SECURITY.md](../SECURITY.md).

## Package layout

| Path | Contents |
| --- | --- |
| `src/Tagging.php`, `src/TagSpace.php` | Entry point and the immutable tag space every API hangs off |
| `src/Concerns`, `src/Models`, `src/Relations` | `HasTags` trait, `Tag` / `Assignment` models, the string-key morph relation |
| `src/Query` | Matching, facets, eager loading, assignment filters |
| `src/Vocabulary` | Lifecycle, merge/delete, import/export, health checks, duplicate suggestions |
| `src/Bulk`, `src/Automation`, `src/Jobs` | Bulk tagging over queries, rule-based auto-tagging, the queued `TagSubject` job |
| `src/Governance`, `src/Taxonomy`, `src/Tenancy`, `src/Hierarchy` | Assignment policies, taxonomy definitions, tenant guards, hierarchy strategy |
| `src/History`, `src/Events`, `src/Operations` | Review decisions and provenance, domain events, pruning and diagnostics |
| `src/Contracts`, `src/Exceptions`, `src/Rules`, `src/Console` | Extension points, exceptions, the `TagSelection` validation rule, Artisan commands |
| `config`, `database/migrations`, `lang` | Published configuration, schema, validation messages |

The raw `tagAssignments` relationship intentionally includes every space, inactive tag, and expired assignment. Use the contextual APIs for tenant-aware reads. Direct model updates and raw SQL bypass domain rules and event/provenance coverage.

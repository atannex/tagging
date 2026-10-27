<?php

require __DIR__.'/../tests/bootstrap.php';

use Atannex\Tagging\Tagging;
use Atannex\Tagging\Tests\Fixtures\Record;
use Atannex\Tagging\Tests\Support\EngineTestCase;
use Illuminate\Support\Facades\DB;

final class BenchmarkRuntime extends EngineTestCase
{
    public function benchmark(): void {}

    public function start(): void
    {
        parent::setUp();
    }
}
$tags = (int) ($argv[1] ?? 500);
$subjects = (int) ($argv[2] ?? 1000);
$perSubject = 5;
if ($tags < 10 || $tags > 20000 || $subjects < 10 || $subjects > 100000) {
    throw new InvalidArgumentException('Use 10–20000 tags and 10–100000 subjects.');
}
$runtime = new BenchmarkRuntime('benchmark');
$runtime->start();
$start = hrtime(true);
$time = now()->format('Y-m-d H:i:s');
$insert = function ($table, $rows) {
    foreach (array_chunk($rows, 200) as $batch) {
        DB::table($table)->insert($batch);
    }
};
$rows = [];
$names = [];
$depths = [];
for ($i = 1; $i <= $tags; $i++) {
    $parent = $i === 1 ? null : (int) floor(($i - 2) / 5) + 1;
    $depths[$i] = $parent === null ? 1 : $depths[$parent] + 1;
    $rows[] = ['id' => $i, 'tenant' => 'bench', 'taxonomy' => 'tags', 'name' => 'Topic '.$i, 'slug' => 'topic-'.$i, 'parent_id' => $parent, 'active' => true, 'created_at' => $time, 'updated_at' => $time];
    $names[] = ['tag_id' => $i, 'tenant' => 'bench', 'taxonomy' => 'tags', 'slug' => 'topic-'.$i];
}
$insert('tagging_tags', $rows);
$insert('tagging_names', $names);
$rows = [];
for ($i = 1; $i <= $subjects; $i++) {
    $rows[] = ['id' => $i, 'tenant' => 'bench', 'created_at' => $time, 'updated_at' => $time];
}
$insert('engine_records', $rows);
$rows = [];
for ($i = 1; $i <= $subjects; $i++) {
    for ($j = 0; $j < $perSubject; $j++) {
        $rows[] = ['tag_id' => (($i * 7 + $j) % $tags) + 1, 'taggable_type' => Record::class, 'taggable_id' => (string) $i, 'context' => 'default', 'weight' => ($j + 1) / 2, 'source' => 'fixture', 'created_at' => $time, 'updated_at' => $time];
    }
    if (count($rows) >= 500) {
        $insert('tagging_assignments', $rows);
        $rows = [];
    }
}
if ($rows) {
    $insert('tagging_assignments', $rows);
}
$space = app(Tagging::class)->for('bench');
$root = $space->query()->findOrFail(1);
$models = Record::limit(100)->get();
$measurements = [];
$measure = function ($name, $callback) use (&$measurements) {
    DB::flushQueryLog();
    DB::enableQueryLog();
    memory_reset_peak_usage();
    $started = hrtime(true);
    $result = $callback();
    $measurements[$name] = ['duration_ms' => round((hrtime(true) - $started) / 1e6, 3), 'queries' => count(DB::getQueryLog()), 'peak_bytes' => memory_get_peak_usage(true), 'result_count' => is_countable($result) ? count($result) : null];
    DB::disableQueryLog();
};
$measure('descendants', fn () => $space->descendants($root));
$measure('popular', fn () => $space->popular(20));
$measure('matching', fn () => Record::withAnyTags(['Topic 10'], $space)->limit(50)->get());
$measure('facets', fn () => $space->facets(Record::query(), 20));
$measure('eager_100', fn () => $space->eagerLoad($models));
$version = DB::getDriverName() === 'sqlite' ? DB::selectOne('select sqlite_version() as version')->version : DB::selectOne('select version() as version')->version;
$probe = Record::withAnyTags(['Topic 10'], $space)->limit(50);
$plan = DB::getDriverName() === 'sqlite' ? DB::select('EXPLAIN QUERY PLAN '.$probe->toSql(), $probe->getBindings()) : null;
$cpu = null;
if (is_readable('/proc/cpuinfo') && preg_match('/^model name\s*:\s*(.+)$/m', file_get_contents('/proc/cpuinfo'), $matches)) {
    $cpu = trim($matches[1]);
}
$quota = is_readable('/sys/fs/cgroup/cpu.max') ? trim(file_get_contents('/sys/fs/cgroup/cpu.max')) : null;
echo json_encode([
    'environment' => ['php' => PHP_VERSION, 'laravel' => app()->version(), 'database' => DB::getDriverName(), 'database_version' => $version, 'os' => PHP_OS_FAMILY, 'architecture' => php_uname('m'), 'cpu_model' => $cpu, 'cpu_quota' => $quota, 'php_memory_limit' => ini_get('memory_limit')],
    'dataset' => ['tags' => $tags, 'subjects' => $subjects, 'assignments' => $subjects * $perSubject, 'max_depth' => max($depths), 'branching_factor' => 5],
    'total_including_fixture_ms' => round((hrtime(true) - $start) / 1e6, 3),
    'matching_plan' => $plan, 'measurements' => $measurements,
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;

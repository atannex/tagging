<?php

require __DIR__.'/../bootstrap.php';

use Atannex\Tagging\Events\VocabularyChanged;
use Atannex\Tagging\History\ReviewedSourcePolicy;
use Atannex\Tagging\Support\SpaceTransactions;
use Atannex\Tagging\Tagging;
use Atannex\Tagging\Tests\Fixtures\Record;
use Atannex\Tagging\Tests\Support\WorkerRuntime;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

$payload = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
$runtime = new WorkerRuntime('worker');
$runtime->start();
config(['tagging.provenance.enabled' => true]);
if (isset($payload['definition'])) {
    config(['tagging.taxonomies.tags' => $payload['definition']]);
}
if (! empty($payload['review'])) {
    config(['tagging.review_tracking' => true, 'tagging.source_policy' => ReviewedSourcePolicy::class]);
}
$driver = DB::getDriverName();
$pid = $driver === 'pgsql' ? DB::selectOne('select pg_backend_pid() as pid')->pid : DB::selectOne('select connection_id() as pid')->pid;
$events = 0;
Event::listen(VocabularyChanged::class, function () use (&$events) {
    $events++;
});
file_put_contents($payload['ready'], json_encode(['pid' => $pid], JSON_THROW_ON_ERROR));
$space = app(Tagging::class)->for($payload['tenant'] ?? '', 'tags');
$attempts = 0;
$completed = 0;
if (in_array($payload['action'], ['serialization', 'commit_serialization'], true)) {
    DB::statement('SET SESSION CHARACTERISTICS AS TRANSACTION ISOLATION LEVEL SERIALIZABLE');
}
try {
    $result = match ($payload['action']) {
        'create' => ['id' => $space->create($payload['name'])->id],
        'delete' => (function () use ($space, $payload) {
            $space->delete($space->query()->findOrFail($payload['tag_id']));

            return [];
        })(),
        'alias' => (function () use ($space, $payload) {
            $space->alias($space->query()->findOrFail($payload['tag_id']), $payload['name']);

            return [];
        })(),
        'sync' => (function () use ($space, $payload) {
            $space->sync(Record::findOrFail($payload['subject_id']), $payload['tags']);

            return [];
        })(),
        'attach' => (function () use ($space, $payload) {
            $space->attach(Record::findOrFail($payload['subject_id']), $payload['tags'], $payload['attributes'] ?? []);

            return [];
        })(),
        'move' => (function () use ($space, $payload) {
            $space->move($space->query()->findOrFail($payload['tag_id']), $space->query()->findOrFail($payload['parent_id']));

            return [];
        })(),
        'deadlock', 'serialization', 'commit_serialization' => (function () use ($space, $payload, &$attempts, &$completed) {
            return app(SpaceTransactions::class)->run($space, function () use ($space, $payload, &$attempts, &$completed) {
                $attempts++;
                if ($payload['action'] !== 'deadlock') {
                    $space->create($payload['name']);
                }
                $rowId = $payload['row_id'] ?? 1;
                $value = $payload['action'] === 'commit_serialization' ? DB::table('race_counters')->orderBy('id')->get()->firstWhere('id', $rowId)->value : DB::table('race_counters')->where('id', 1)->value('value');
                if ($attempts === 1) {
                    file_put_contents($payload['held'], 'held');
                    $deadline = microtime(true) + 15;
                    while (! is_file($payload['release'])) {
                        if (microtime(true) > $deadline) {
                            throw new RuntimeException('Barrier timed out');
                        } usleep(10000);
                    }
                }
                if ($payload['action'] === 'deadlock') {
                    app(SpaceTransactions::class)->run(app(Tagging::class)->for($payload['other']), fn () => null);
                }
                if ($payload['action'] === 'deadlock') {
                    $space->create($payload['name']);
                }
                DB::table('race_counters')->where('id', $rowId)->update(['value' => $value + 1]);
                if ($payload['action'] === 'commit_serialization' && $attempts === 1) {
                    file_put_contents($payload['prepared'], 'prepared');
                    $deadline = microtime(true) + 15;
                    while (! is_file($payload['commit_release'])) {
                        if (microtime(true) > $deadline) {
                            throw new RuntimeException('Commit barrier timed out');
                        } usleep(10000);
                    }
                }
                $completed++;

                return [];
            });
        })(),
        default => throw new RuntimeException('Unknown worker action'),
    };
    $output = ['ok' => true, 'result' => $result, 'attempts' => $attempts, 'events' => $events, 'completed' => $completed];
} catch (Throwable $e) {
    $output = ['ok' => false, 'exception' => $e::class, 'message' => $e->getMessage(), 'attempts' => $attempts, 'events' => $events, 'completed' => $completed];
}
file_put_contents($payload['result'], json_encode($output, JSON_THROW_ON_ERROR));

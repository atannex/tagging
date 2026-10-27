<?php

namespace Atannex\Tagging\Support;

use Atannex\Tagging\TagSpace;
use Atannex\Tagging\Tenancy\ScopeGuard;
use Closure;
use Illuminate\Database\DeadlockException;
use Illuminate\Support\Facades\DB;
use PDOException;
use Throwable;

final class SpaceTransactions
{
    private array $frames = [];

    /** A persistent namespace row serializes mutations, including an initially empty taxonomy. */
    public function run(TagSpace $space, Closure $callback): mixed
    {
        Settings::validate();
        app(ScopeGuard::class)->space($space);
        $connection = DB::connection();
        $name = $connection->getName();
        $level = $connection->transactionLevel();
        $parent = empty($this->frames[$name]) ? null : end($this->frames[$name]);
        // An after-commit listener may start another transaction while the old PHP frame still exists.
        $owner = $level > 0 && $parent && $parent->phase === 'callback' ? $parent->owner : new \stdClass;
        $frame = (object) ['owner' => $owner, 'phase' => 'callback'];
        $this->frames[$name][] = $frame;
        $attempts = $level === 0 ? config('tagging.transaction_attempts', 3) : 1;
        try {
            for ($attempt = 1; $attempt <= $attempts; $attempt++) {
                $frame->phase = 'callback';
                $connection->beginTransaction();
                try {
                    $key = hash('sha256', json_encode([$space->tenant, $space->taxonomy], JSON_THROW_ON_ERROR));
                    // A non-erroring conflict insert does not poison a PostgreSQL transaction.
                    DB::table('tagging_space_locks')->insertOrIgnore(['key' => $key]);
                    DB::table('tagging_space_locks')->where('key', $key)->lockForUpdate()->first();
                    $result = $callback();
                    if ($connection->transactionLevel() !== $level + 1) {
                        throw new \LogicException('Do not manually commit/roll back or swallow transaction aborts inside tagging callbacks.');
                    }
                    $frame->phase = 'commit';
                    // Public commit keeps the transaction level intact on a PDO COMMIT failure.
                    // rollBack() below can then clear both PDO state and queued transaction callbacks before replay.
                    $connection->commit();

                    return $result;
                } catch (Throwable $exception) {
                    $active = $connection->transactionLevel() > $level;
                    $abortedHere = $exception instanceof AbortedTransaction && $exception->owner === $owner;
                    // A listener error after successful commit must never replay already-committed work.
                    if (! $active && ! $abortedHere) {
                        throw $exception;
                    }
                    if ($this->concurrency($exception)) {
                        if ($connection->transactionLevel() > 0) {
                            $connection->rollBack(0);
                        }
                        if ($level > 0) {
                            throw new AbortedTransaction($owner, $exception);
                        }
                        if ($attempt < $attempts) {
                            continue;
                        }
                        throw $exception;
                    }
                    if ($active) {
                        $connection->rollBack($level);
                    }
                    throw $exception;
                }
            }
            throw new \LogicException('Unreachable transaction retry state.');
        } finally {
            array_pop($this->frames[$name]);
            if (! $this->frames[$name]) {
                unset($this->frames[$name]);
            }
        }
    }

    private function concurrency(Throwable $exception): bool
    {
        for ($current = $exception; $current; $current = $current->getPrevious()) {
            if ($current instanceof \InvalidArgumentException || $current instanceof \DomainException) {
                return false;
            }
            if ($current instanceof DeadlockException) {
                return true;
            }
            if ($current instanceof PDOException) {
                $state = (string) ($current->errorInfo[0] ?? $current->getCode());
                $number = (int) ($current->errorInfo[1] ?? 0);
                if (in_array($state, ['40001', '40P01'], true)) {
                    return true;
                }
                if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true) && in_array($number, [1205, 1213], true)) {
                    return true;
                }
                if (DB::getDriverName() === 'sqlite' && in_array($number, [5, 6], true)) {
                    return true;
                }
            }
        }

        return false;
    }
}

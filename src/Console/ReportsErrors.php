<?php

namespace Atannex\Tagging\Console;

use Atannex\Tagging\Exceptions\ImportFailure;
use Atannex\Tagging\Exceptions\PolicyRejected;
use Closure;
use PDOException;
use Throwable;

trait ReportsErrors
{
    /** PDO messages can interpolate metadata bindings. Operational output must not render those messages. */
    protected function safely(Closure $operation): int
    {
        try {
            return $operation();
        } catch (PDOException $exception) {
            $state = (string) ($exception->errorInfo[0] ?? $exception->getCode());
            $state = preg_match('/^[A-Z0-9]{5}$/D', $state) ? $state : 'unknown';
            $this->error('Database operation failed (SQLSTATE '.$state.'). Run tagging:health and inspect current state before retrying.');

            return self::FAILURE;
        } catch (\InvalidArgumentException|\DomainException $exception) {
            $this->error($this->safeMessage($exception));

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->error('Tagging operation did not complete normally ('.$exception::class.'). Inspect current state and application integration before retrying.');

            return self::FAILURE;
        }
    }

    protected function safeMessage(Throwable $exception): string
    {
        if ($exception instanceof PolicyRejected || ($exception instanceof ImportFailure && $exception->getPrevious() instanceof PolicyRejected)) {
            return 'Taxonomy, source, or metadata policy rejected the operation. Check the selected policy configuration.';
        }

        return $exception->getMessage();
    }
}

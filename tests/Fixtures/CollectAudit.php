<?php

namespace Atannex\Tagging\Tests\Fixtures;

use Atannex\Tagging\Contracts\AuditSink;

class CollectAudit implements AuditSink
{
    public array $events = [];

    public function record(object $event): void
    {
        $this->events[] = $event;
    }
}

<?php

namespace Atannex\Tagging\Contracts;

interface AuditSink
{
    /** Called after commit. Delivery is best effort, not a durable outbox. */
    public function record(object $event): void;
}

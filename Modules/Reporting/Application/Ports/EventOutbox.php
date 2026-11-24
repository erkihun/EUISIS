<?php

declare(strict_types=1);

namespace Modules\Reporting\Application\Ports;

/**
 * Stores an integration event (a class of Contracts\Events) in the CURRENT
 * database transaction. It is relayed after the commit and again by the
 * scheduled relay until it has been dispatched, so a crash between commit and
 * queue cannot lose it. Delivery is at least once; consumers must be
 * idempotent on the event id.
 */
interface EventOutbox
{
    /** @param object $event a final readonly class with NAME and VERSION constants and an `eventId` */
    public function store(object $event): void;
}

<?php

declare(strict_types=1);

namespace Modules\Reporting\Contracts\Events;

/**
 * An effective deadline passed with no valid submission. Published once per
 * obligation. See ReportSubmittedV1 for the delivery rules.
 */
final readonly class ReportingDeadlineMissedV1
{
    public const NAME = 'reporting.deadline_missed';

    public const VERSION = 1;

    public function __construct(
        public string $eventId,
        /** ISO 8601, UTC. */
        public string $occurredAt,
        public string $obligationId,
        public string $organizationId,
        public string $reportTypeId,
        public string $cycleId,
        public string $effectiveDueAt,
    ) {}
}

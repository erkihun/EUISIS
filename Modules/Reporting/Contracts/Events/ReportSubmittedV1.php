<?php

declare(strict_types=1);

namespace Modules\Reporting\Contracts\Events;

/**
 * A version of a report was submitted (a first submission or a resubmission).
 *
 * Integration events carry identifiers and facts, never the narrative. They
 * are versioned by class name: a breaking change is a new class (…V2).
 * Delivery is at least once; `eventId` is unique per event, so a consumer that
 * must not act twice records the ids it has handled.
 */
final readonly class ReportSubmittedV1
{
    public const NAME = 'reporting.report_submitted';

    public const VERSION = 1;

    public function __construct(
        public string $eventId,
        /** ISO 8601, UTC. */
        public string $occurredAt,
        public string $obligationId,
        public string $submissionId,
        public int $versionNo,
        public string $referenceNo,
        public string $organizationId,
        public string $reportTypeId,
        public string $cycleId,
        public bool $isFirstSubmission,
        public bool $isLate,
    ) {}
}

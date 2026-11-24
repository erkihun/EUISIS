<?php

declare(strict_types=1);

namespace Modules\Reporting\Contracts\Events;

/** The last review step approved a submitted version. See ReportSubmittedV1 for the delivery rules. */
final readonly class ReportApprovedV1
{
    public const NAME = 'reporting.report_approved';

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
    ) {}
}

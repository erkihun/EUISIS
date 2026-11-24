<?php

declare(strict_types=1);

namespace Modules\Reporting\Domain\Compliance;

use DateTimeImmutable;
use Modules\Reporting\Domain\Enums\Applicability;
use Modules\Reporting\Domain\Enums\WorkflowStatus;

/**
 * What the compliance rules need to know about one obligation, already
 * resolved for the as-of time: the deadline and applicability in force then,
 * and the first valid submission if it had happened by then.
 */
final readonly class ObligationFacts
{
    public function __construct(
        public Applicability $applicability,
        public WorkflowStatus $workflow,
        public DateTimeImmutable $originalDueAt,
        public DateTimeImmutable $effectiveDueAt,
        public ?DateTimeImmutable $firstSubmittedAt = null,
        public ?DateTimeImmutable $reviewDueAt = null,
    ) {}
}

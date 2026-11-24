<?php

declare(strict_types=1);

namespace Modules\Reporting\Contracts\Data;

/**
 * Reporting compliance for a set of organizations at a moment: counts and
 * rates only, no narrative. Rates are fractions (0–1); null means the
 * denominator was zero and the figure does not apply.
 */
final readonly class ComplianceSummary
{
    /** @param array<string, int> $counts keyed as in Modules\Reporting\Domain\Compliance\ComplianceTally */
    public function __construct(
        public string $asOf,
        public array $counts,
        public ?float $submissionRate,
        public ?float $onTimeRate,
        public ?float $approvalRate,
    ) {}
}

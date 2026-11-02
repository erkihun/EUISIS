<?php

declare(strict_types=1);

namespace App\Services\ReportingLines;

/** One position as the reporting graph sees it on the graph's date (ADR 0025). */
final readonly class PositionNode
{
    public function __construct(
        public string $id,
        public string $organizationId,
        public ?string $unitId,
        public bool $isUnitHead,
        public bool $isActiveOnDate,
        public string $titleEn,
        public ?string $titleAm,
        public ?string $jobPositionCode,
        /** The in-force published profile's reports_to_position_id, if any. */
        public ?string $explicitReportsTo = null,
    ) {}

    /** Head of the organization itself: marked as head and placed in no unit. */
    public function isOrganizationHead(): bool
    {
        return $this->isUnitHead && $this->unitId === null;
    }
}

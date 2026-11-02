<?php

declare(strict_types=1);

namespace App\Services\ReportingLines;

use App\Enums\OccupantBasis;

/** A person holding a position on the graph's date, substantively or acting (ADR 0025). */
final readonly class Occupant
{
    public function __construct(
        public string $positionId,
        public string $employeeId,
        public OccupantBasis $basis,
        public ?string $employeeNumber,
        public ?string $name,
        public ?string $nameAm,
        public bool $employeeActive,
        public ?string $assignmentId = null,
        public ?string $actingAppointmentId = null,
    ) {}
}

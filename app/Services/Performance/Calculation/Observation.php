<?php

declare(strict_types=1);

namespace App\Services\Performance\Calculation;

/** One measured value (a KPI actual row, or a child's aggregate) as input to aggregation. */
final readonly class Observation
{
    public function __construct(
        public ?string $value,
        public ?string $numerator = null,
        public ?string $denominator = null,
        public ?string $weight = null,
        public string $periodEnd = '',
        public ?string $milestoneKey = null,
        public string $contributor = '',
    ) {}
}

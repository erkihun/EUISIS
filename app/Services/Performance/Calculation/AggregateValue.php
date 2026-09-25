<?php

declare(strict_types=1);

namespace App\Services\Performance\Calculation;

/** Result of aggregating observations; carries totals so a parent can re-aggregate correctly. */
final readonly class AggregateValue
{
    public function __construct(
        public ?string $value,
        public string $method,
        public string $formula,
        public int $count = 0,
        public ?string $numerator = null,
        public ?string $denominator = null,
        public ?string $weight = null,
        public ?string $milestoneKey = null,
        public string $periodEnd = '',
    ) {}

    public function toObservation(string $contributor): Observation
    {
        return new Observation($this->value, $this->numerator, $this->denominator, $this->weight, $this->periodEnd, $this->milestoneKey, $contributor);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'value' => $this->value,
            'method' => $this->method,
            'formula' => $this->formula,
            'count' => $this->count,
            'numerator' => $this->numerator,
            'denominator' => $this->denominator,
            'weight' => $this->weight,
            'milestone_key' => $this->milestoneKey,
        ];
    }
}

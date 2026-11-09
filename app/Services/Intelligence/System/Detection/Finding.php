<?php

declare(strict_types=1);

namespace App\Services\Intelligence\System\Detection;

use App\Models\IntelligenceIncident;

/**
 * What a detection rule observed this run: one condition on one component
 * (and dimension), with the severity the rule assigned from its configured
 * limit or baseline, the evidence that says so, and the signals it read.
 * The IncidentManager turns findings into incidents; the same fingerprint
 * on consecutive runs is the same incident.
 */
final readonly class Finding
{
    /**
     * @param  array<string, string|int|float>  $params  Fill the title and impact templates.
     * @param  list<array{label: string, value: string|int|float|null}>  $evidence
     * @param  list<string>  $signals  Metric names read.
     */
    public function __construct(
        public string $rule,
        public string $component,
        public ?string $dimension,
        public string $severity,
        public array $params = [],
        public array $evidence = [],
        public array $signals = [],
        public ?string $endpoint = null,
        public ?string $module = null,
    ) {
        if (! in_array($severity, IntelligenceIncident::SEVERITIES, true)) {
            throw new \InvalidArgumentException("Unknown severity [{$severity}].");
        }
    }

    public function fingerprint(): string
    {
        return sha1($this->rule.'|'.($this->dimension ?? ''));
    }

    public function weight(): int
    {
        return (int) array_search($this->severity, array_reverse(IntelligenceIncident::SEVERITIES), true);
    }

    /** @return array{params: array<string, string|int|float>, rows: list<array{label: string, value: string|int|float|null}>} */
    public function evidencePayload(): array
    {
        return ['params' => $this->params, 'rows' => $this->evidence];
    }
}

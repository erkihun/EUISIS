<?php

declare(strict_types=1);

namespace App\Services\ReportingLines;

use App\Enums\ReportingBasis;
use App\Enums\ReportingOutcome;

/**
 * Which position supervises a given position, and by which rule — or why
 * none could be named (ADR 0025). People are resolved separately.
 */
final readonly class PositionLink
{
    /** @param array<string, string|int|null> $explanationParams */
    public function __construct(
        public ReportingOutcome $outcome,
        public ?ReportingBasis $basis = null,
        public ?string $supervisorPositionId = null,
        public string $explanation = 'reporting-lines.explanations.unknown',
        public array $explanationParams = [],
    ) {}

    public static function resolved(ReportingBasis $basis, string $positionId, string $explanation, array $params = []): self
    {
        return new self(ReportingOutcome::Resolved, $basis, $positionId, $explanation, $params);
    }

    public static function top(string $explanation, array $params = []): self
    {
        return new self(ReportingOutcome::TopOfHierarchy, null, null, $explanation, $params);
    }

    public static function problem(ReportingOutcome $outcome, string $explanation, array $params = [], ?ReportingBasis $basis = null, ?string $positionId = null): self
    {
        return new self($outcome, $basis, $positionId, $explanation, $params);
    }

    public function isResolved(): bool
    {
        return $this->outcome === ReportingOutcome::Resolved && $this->supervisorPositionId !== null;
    }
}

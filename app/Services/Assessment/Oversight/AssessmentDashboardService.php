<?php

declare(strict_types=1);

namespace App\Services\Assessment\Oversight;

use App\Models\AssessmentCycle;
use Throwable;

/** Dashboard projections reuse the authoritative assessment queries. */
class AssessmentDashboardService
{
    public function __construct(private readonly AssessmentOversightQueryService $query) {}

    /** An optional projection failure must not hide the core coverage summary. */
    public function optional(callable $load): array
    {
        try {
            return ['available' => true, 'data' => $load()];
        } catch (Throwable $error) {
            report($error);

            return ['available' => false, 'data' => null];
        }
    }

    public function getOperationalDetails(AssessmentCycle $cycle, OversightScope $scope): array
    {
        return [
            'forms' => $this->optional(fn () => $this->query->formUsage($cycle, $scope)),
            'evaluators' => $this->optional(fn () => $this->query->peerCompletion($cycle, $scope)),
            'reasons' => $this->optional(fn () => $this->query->reasons($cycle, $scope)),
        ];
    }

    /** Counts are issue occurrences; an employee can have more than one issue. */
    public function getActionRequired(array $quality, array $institutions): array
    {
        $actions = [];
        foreach ($quality['rules'] ?? [] as $code => $rule) {
            if ($rule['count'] > 0 && $rule['severity'] === 'blocking') {
                $actions[] = ['priority' => 'blocking', 'code' => $code, 'count' => $rule['count'], 'route' => 'assessment-oversight.data-quality', 'filters' => ['code' => $code]];
            }
        }
        if (($institutions['overdue'] ?? 0) > 0) {
            $actions[] = ['priority' => 'overdue', 'code' => 'overdue', 'count' => $institutions['overdue'], 'route' => 'assessment-oversight.institutions', 'filters' => []];
        }
        foreach ($quality['rules'] ?? [] as $code => $rule) {
            if ($rule['count'] > 0 && $rule['severity'] === 'warning') {
                $actions[] = ['priority' => 'warning', 'code' => $code, 'count' => $rule['count'], 'route' => 'assessment-oversight.data-quality', 'filters' => ['code' => $code]];
            }
        }

        return $actions;
    }
}

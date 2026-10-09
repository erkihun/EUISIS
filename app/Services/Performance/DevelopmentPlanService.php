<?php

declare(strict_types=1);

namespace App\Services\Performance;

use App\Enums\AuditEventType;
use App\Enums\Performance\DevelopmentStatus;
use App\Models\EmployeePerformanceAgreement;
use App\Models\IndividualDevelopmentPlan;
use App\Models\PerformanceImprovementPlan;
use App\Models\PerformanceResult;
use App\Models\User;
use App\Services\Performance\Calculation\Dec;

/**
 * Improvement (PIP) and development (IDP) plans. A score below the configured
 * threshold RECOMMENDS a PIP to the manager; nothing is imposed automatically
 * and nothing punitive happens here. PIPs are confidential HR data.
 */
final class DevelopmentPlanService
{
    public function __construct(
        private readonly EpmsAccess $access,
        private readonly EpmsAudit $audit,
        private readonly EpmsSettings $settings,
    ) {}

    public function recommendsImprovementPlan(PerformanceResult $result): bool
    {
        return Dec::of($result->final_score)?->isLessThan($this->settings->pipThreshold()) ?? false;
    }

    /** @param array<string, mixed> $data */
    public function createImprovementPlan(EmployeePerformanceAgreement $agreement, array $data, User $actor): PerformanceImprovementPlan
    {
        $this->access->authorize($actor->can('performance_reviews.manage') && $this->access->isManagerOf($actor, $agreement));

        $plan = new PerformanceImprovementPlan([
            ...$data,
            'employee_id' => $agreement->employee_id,
            'agreement_id' => $agreement->getKey(),
            'result_id' => $agreement->results()->where('is_current', true)->value('id'),
            'organization_id' => $agreement->organization_id,
            'created_by' => $actor->getKey(),
        ]);
        $plan->forceFill(['status' => DevelopmentStatus::Active])->save();
        $this->audit->record(AuditEventType::PerformanceDevelopmentPlanChanged, $actor, $plan, ['type' => 'PIP', 'status' => 'ACTIVE']);

        return $plan;
    }

    /** @param array<string, mixed> $data The employee may propose their own IDP; the manager may add one. */
    public function createDevelopmentPlan(EmployeePerformanceAgreement $agreement, array $data, User $actor): IndividualDevelopmentPlan
    {
        $own = $this->access->isOwn($actor, $agreement);
        $this->access->authorize($own || $actor->can('performance_reviews.manage') && $this->access->isManagerOf($actor, $agreement));

        $plan = new IndividualDevelopmentPlan([
            ...$data,
            'employee_id' => $agreement->employee_id,
            'cycle_id' => $agreement->cycle_id,
            'agreement_id' => $agreement->getKey(),
            'created_by' => $actor->getKey(),
        ]);
        $plan->forceFill(['status' => $own ? DevelopmentStatus::Draft : DevelopmentStatus::Active])->save();
        $this->audit->record(AuditEventType::PerformanceDevelopmentPlanChanged, $actor, $agreement, ['type' => 'IDP', 'status' => $plan->status->value]);

        return $plan;
    }
}

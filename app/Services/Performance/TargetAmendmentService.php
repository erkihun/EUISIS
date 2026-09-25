<?php

declare(strict_types=1);

namespace App\Services\Performance;

use App\Enums\AuditEventType;
use App\Enums\Performance\AgreementStatus;
use App\Enums\Performance\AmendmentStatus;
use App\Enums\Performance\PlanStatus;
use App\Models\EmployeePerformanceItem;
use App\Models\KpiTarget;
use App\Models\PerformanceTargetAmendment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Formal target change after publication/activation (docs/epms-cascade-rules.md §5).
 *
 * Original target → amendment request (reason, effective date) → approval by
 * someone else → NEW version row; the old one stays (is_current = false).
 * Actuals already recorded remain linked to the version they were measured
 * against; the score uses the current version's target over the whole chain.
 */
final class TargetAmendmentService
{
    private const FIELDS = ['target_value', 'target_numerator', 'target_denominator', 'weight', 'achievement_cap', 'tolerance', 'zero_score_deviation'];

    public function __construct(
        private readonly EpmsAccess $access,
        private readonly EpmsAudit $audit,
        private readonly EpmsSettings $settings,
    ) {}

    /** @param array<string, mixed> $values */
    public function request(KpiTarget|EmployeePerformanceItem $subject, array $values, string $reason, string $effectiveDate, User $actor): PerformanceTargetAmendment
    {
        $this->assertAuthority($subject, $actor, 'kpi_targets.manage');
        $proposed = array_intersect_key($values, array_flip(self::FIELDS));
        if ($proposed === [] || trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => __('performance.errors.reason_required')]);
        }

        $amendment = PerformanceTargetAmendment::query()->create([
            'subject_type' => $subject instanceof KpiTarget ? 'TARGET' : 'ITEM',
            'subject_id' => $subject->getKey(),
            'original_values' => $subject->only(self::FIELDS),
            'proposed_values' => $proposed,
            'reason' => $reason,
            'effective_date' => $effectiveDate,
            'requested_by' => $actor->getKey(),
        ]);

        if (! $this->settings->amendmentRequiresApproval()) {
            return $this->decide($amendment, true, $actor, bypassSeparation: true);
        }

        return $amendment;
    }

    public function decide(PerformanceTargetAmendment $amendment, bool $approve, User $actor, bool $bypassSeparation = false): PerformanceTargetAmendment
    {
        $subject = $amendment->subject_type === 'TARGET'
            ? KpiTarget::query()->findOrFail($amendment->subject_id)
            : EmployeePerformanceItem::query()->findOrFail($amendment->subject_id);
        $this->assertAuthority($subject, $actor, 'performance_plans.approve');
        if (! $bypassSeparation) {
            $this->access->assertSeparated($actor, $amendment->requested_by, 'approve amendment');
        }
        if ($amendment->status !== AmendmentStatus::Pending) {
            throw ValidationException::withMessages(['amendment' => __('performance.errors.stale')]);
        }

        return DB::transaction(function () use ($amendment, $subject, $approve, $actor): PerformanceTargetAmendment {
            $newId = null;
            if ($approve) {
                $locked = $subject->newQuery()->whereKey($subject->getKey())->lockForUpdate()->firstOrFail();
                if (! $locked->is_current) {
                    throw ValidationException::withMessages(['amendment' => __('performance.errors.stale')]);
                }
                $next = $locked->replicate();
                $next->forceFill([
                    ...$amendment->proposed_values,
                    'is_current' => true,
                    'effective_from' => $amendment->effective_date,
                    'amendment_reason' => $amendment->reason,
                ]);
                if ($locked instanceof KpiTarget) {
                    $next->forceFill(['amended_from_id' => $locked->getKey(), 'version_no' => $locked->version_no + 1]);
                } else {
                    $next->forceFill(['supersedes_item_id' => $locked->getKey()]);
                }
                $locked->forceFill(['is_current' => false])->save();
                $next->save();

                // Children keep pointing at the current version of their parent target.
                if ($locked instanceof KpiTarget) {
                    KpiTarget::query()->where('parent_target_id', $locked->getKey())->update(['parent_target_id' => $next->getKey()]);
                    EmployeePerformanceItem::query()->where('position_target_id', $locked->getKey())->update(['position_target_id' => $next->getKey()]);
                }
                $newId = $next->getKey();
            }

            $amendment->forceFill([
                'status' => $approve ? AmendmentStatus::Approved : AmendmentStatus::Rejected,
                'new_subject_id' => $newId,
                'decided_by' => $actor->getKey(),
                'decided_at' => now(),
            ])->save();

            $this->audit->record(AuditEventType::KpiTargetAmended, $actor, $amendment, [
                'status' => $amendment->status->value, 'proposed' => $amendment->proposed_values,
            ], $amendment->original_values, $amendment->reason);

            return $amendment;
        });
    }

    private function assertAuthority(KpiTarget|EmployeePerformanceItem $subject, User $actor, string $permission): void
    {
        if ($subject instanceof KpiTarget) {
            $this->access->authorize($this->access->inScope($actor, $permission, $subject->plan->organization_id));
            if ($subject->plan->status !== PlanStatus::Published) {
                throw ValidationException::withMessages(['target' => __('performance.errors.amend_only_published')]);
            }

            return;
        }

        $agreement = $subject->agreement;
        $this->access->authorize($actor->can($permission) && $this->access->canManageAgreement($actor, $agreement));
        if (! in_array($agreement->status, [AgreementStatus::Active, AgreementStatus::Agreed], true)) {
            throw ValidationException::withMessages(['item' => __('performance.errors.amend_only_active')]);
        }
    }
}

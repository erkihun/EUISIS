<?php

declare(strict_types=1);

namespace App\Services\Performance;

use App\Enums\AuditEventType;
use App\Enums\Performance\AdjustmentStatus;
use App\Enums\Performance\AgreementStatus;
use App\Enums\Performance\ResultStatus;
use App\Enums\Performance\ReviewStatus;
use App\Enums\Performance\ReviewType;
use App\Models\EmployeePerformanceAgreement;
use App\Models\PerformanceResult;
use App\Models\PerformanceScoreAdjustment;
use App\Models\User;
use App\Services\Performance\Calculation\Dec;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Employee results (docs/epms-workflow.md §5).
 *
 *   calculate   formula result (EmployeeScoreCalculator) — repeatable until final
 *   adjust      explicit request + reason, approved by someone else; the
 *               calculated score is kept beside it, never overwritten
 *   calibrate   PerformanceCalibrationService
 *   finalize    frozen snapshot; the row is immutable from here on
 *   release     the employee sees it (when release is required)
 *
 * A later change (appeal) creates a new revision; the finalized row stays.
 */
final class PerformanceResultService
{
    public const FINAL_STATUSES = [ResultStatus::Finalized, ResultStatus::PendingRelease, ResultStatus::Released];

    public function __construct(
        private readonly EpmsAccess $access,
        private readonly EpmsAudit $audit,
        private readonly EpmsSettings $settings,
        private readonly EmployeeScoreCalculator $calculator,
        private readonly PerformanceNotifier $notifier,
    ) {}

    public static function isFinal(PerformanceResult $result): bool
    {
        return in_array($result->status, self::FINAL_STATUSES, true);
    }

    public function calculate(EmployeePerformanceAgreement $agreement, User $actor): PerformanceResult
    {
        $this->access->authorize($actor->can('performance_reviews.manage') && $this->access->canManageAgreement($actor, $agreement));
        if (! in_array($agreement->status, [AgreementStatus::Active, AgreementStatus::UnderReview, AgreementStatus::Closed], true)) {
            throw ValidationException::withMessages(['agreement' => __('performance.errors.agreement_not_active')]);
        }

        return DB::transaction(function () use ($agreement, $actor): PerformanceResult {
            /** @var PerformanceResult|null $current */
            $current = PerformanceResult::query()->where('agreement_id', $agreement->getKey())->where('is_current', true)->lockForUpdate()->first();
            if ($current !== null && self::isFinal($current)) {
                throw ValidationException::withMessages(['result' => __('performance.errors.result_final')]);
            }

            $trace = $this->calculator->trace($agreement);
            $weights = $this->settings->componentWeights();
            $calculated = $trace['final_score'];
            $final = $current?->adjusted_score ?? $calculated;

            $result = $current ?? new PerformanceResult([
                'employee_id' => $agreement->employee_id,
                'cycle_id' => $agreement->cycle_id,
                'agreement_id' => $agreement->getKey(),
                'organization_id' => $agreement->organization_id,
                'organization_unit_id' => $agreement->organization_unit_id,
            ]);
            $rating = $final === $calculated ? $trace['rating'] : $this->calculator->ratingFor($final);

            $result->forceFill([
                'results_score' => $trace['results_score'],
                'competency_score' => $trace['competency_score'],
                'results_weight' => (string) $weights['results'],
                'competency_weight' => (string) $weights['competency'],
                'calculated_score' => $calculated,
                'final_score' => $final,
                'rating_scale_id' => $rating['scale_id'],
                'rating_band_id' => $rating['band_id'],
                'rating_label_en' => $rating['label_en'],
                'rating_label_am' => $rating['label_am'],
                'status' => $this->settings->requireCalibration() ? ResultStatus::PendingCalibration : ResultStatus::Calculated,
                'calculated_at' => now(),
                'calculated_by' => $actor->getKey(),
                'snapshot_json' => $trace,
            ])->save();

            if ($agreement->status === AgreementStatus::Active) {
                $agreement->forceFill(['status' => AgreementStatus::UnderReview])->save();
            }

            $this->audit->record(AuditEventType::PerformanceScoreCalculated, $actor, $result, [
                'results_score' => $trace['results_score'], 'competency_score' => $trace['competency_score'], 'calculated_score' => $calculated,
            ]);

            return $result;
        });
    }

    public function requestAdjustment(PerformanceResult $result, string $score, string $reason, User $actor): PerformanceScoreAdjustment
    {
        $this->access->authorize($actor->can('performance_reviews.manage') && $this->access->canManageAgreement($actor, $result->agreement));
        if (! $this->settings->allowScoreAdjustment()) {
            throw ValidationException::withMessages(['adjusted_score' => __('performance.errors.adjustment_disabled')]);
        }
        if (self::isFinal($result)) {
            throw ValidationException::withMessages(['result' => __('performance.errors.result_final')]);
        }
        $this->assertScore($score);

        $adjustment = $result->adjustments()->create([
            'adjustment_type' => 'MANAGER',
            'original_score' => $result->calculated_score,
            'adjusted_score' => $score,
            'reason' => $reason,
            'requested_by' => $actor->getKey(),
        ]);
        $this->audit->record(AuditEventType::PerformanceScoreAdjusted, $actor, $result, ['requested_score' => $score, 'status' => 'PENDING'], ['calculated_score' => $result->calculated_score], $reason);

        return $adjustment;
    }

    public function decideAdjustment(PerformanceScoreAdjustment $adjustment, bool $approve, ?string $note, User $actor): PerformanceScoreAdjustment
    {
        $result = $adjustment->result;
        $this->access->authorize($this->access->inScope($actor, 'performance_reviews.finalize', $result->organization_id) && ! $this->access->isOwn($actor, $result->agreement));
        $this->access->assertSeparated($actor, $adjustment->requested_by, 'approve adjustment');
        if ($adjustment->status !== AdjustmentStatus::Pending || self::isFinal($result)) {
            throw ValidationException::withMessages(['adjustment' => __('performance.errors.stale')]);
        }

        return DB::transaction(function () use ($adjustment, $result, $approve, $note, $actor): PerformanceScoreAdjustment {
            $adjustment->forceFill([
                'status' => $approve ? AdjustmentStatus::Approved : AdjustmentStatus::Rejected,
                'decided_by' => $actor->getKey(),
                'decided_at' => now(),
                'decision_note' => $note,
            ])->save();

            if ($approve) {
                $rating = $this->calculator->ratingFor((string) $adjustment->adjusted_score);
                $result->forceFill([
                    'adjusted_score' => $adjustment->adjusted_score,
                    'final_score' => $adjustment->adjusted_score,
                    'rating_band_id' => $rating['band_id'],
                    'rating_label_en' => $rating['label_en'],
                    'rating_label_am' => $rating['label_am'],
                ])->save();
            }

            $this->audit->record(AuditEventType::PerformanceScoreAdjusted, $actor, $result, [
                'status' => $approve ? 'APPROVED' : 'REJECTED', 'final_score' => $result->final_score,
            ], ['calculated_score' => $result->calculated_score], $note);

            return $adjustment;
        });
    }

    public function finalize(PerformanceResult $result, User $actor): PerformanceResult
    {
        $this->access->authorize($this->access->inScope($actor, 'performance_reviews.finalize', $result->organization_id) && ! $this->access->isOwn($actor, $result->agreement));

        if ($result->status !== ResultStatus::Calculated) {
            throw ValidationException::withMessages(['result' => $result->status === ResultStatus::PendingCalibration
                ? __('performance.errors.calibration_required')
                : __('performance.errors.result_final')]);
        }
        $this->assertReadyForFinalization($result);

        return $this->freeze($result, $actor, null);
    }

    /** Shared by finalize() and calibration: status, snapshot, agreement, audit. */
    public function freeze(PerformanceResult $result, User $actor, ?array $calibration): PerformanceResult
    {
        return DB::transaction(function () use ($result, $actor, $calibration): PerformanceResult {
            $snapshot = $result->snapshot_json ?? [];
            $snapshot['final'] = [
                'calculated_score' => $result->calculated_score,
                'adjusted_score' => $result->adjusted_score,
                'calibrated_score' => $result->calibrated_score,
                'final_score' => $result->final_score,
                'rating_en' => $result->rating_label_en,
                'rating_am' => $result->rating_label_am,
                'adjustments' => $result->adjustments()->get(['adjustment_type', 'original_score', 'adjusted_score', 'status', 'reason'])->toArray(),
                'calibration' => $calibration,
                'finalized_at' => now()->toIso8601String(),
            ];

            $result->forceFill([
                'status' => $this->settings->requireResultRelease() ? ResultStatus::PendingRelease : ResultStatus::Released,
                'released_at' => $this->settings->requireResultRelease() ? null : now(),
                'finalized_at' => now(),
                'finalized_by' => $actor->getKey(),
                'snapshot_json' => $snapshot,
            ])->save();

            $result->agreement->forceFill(['status' => AgreementStatus::Finalized])->save();
            $this->audit->record(AuditEventType::PerformanceResultFinalized, $actor, $result, ['final_score' => $result->final_score, 'rating' => $result->rating_label_en]);

            if ($result->status === ResultStatus::Released) {
                $this->notifier->toEmployee($result->employee, 'result_released');
            }

            return $result;
        });
    }

    public function release(PerformanceResult $result, User $actor): PerformanceResult
    {
        $this->access->authorize($this->access->inScope($actor, 'performance_reviews.finalize', $result->organization_id));
        if ($result->status !== ResultStatus::PendingRelease) {
            throw ValidationException::withMessages(['result' => __('performance.errors.stale')]);
        }

        $result->forceFill(['status' => ResultStatus::Released, 'released_at' => now(), 'released_by' => $actor->getKey()])->save();
        $this->audit->record(AuditEventType::PerformanceResultReleased, $actor, $result, ['released_at' => $result->released_at?->toIso8601String()]);
        $this->notifier->toEmployee($result->employee, 'result_released');

        return $result;
    }

    /**
     * Several agreements in one cycle (transfer / temporary duty): a
     * day-weighted combination, shown with its formula. Never stored as a
     * separate "hidden" score.
     *
     * @return array{score: ?string, parts: list<array<string, mixed>>, formula: string}
     */
    public function combinedForCycle(string $employeeId, string $cycleId): array
    {
        $results = PerformanceResult::query()->with('agreement')
            ->where('employee_id', $employeeId)->where('cycle_id', $cycleId)->where('is_current', true)->get();

        $total = Dec::zero();
        $days = Dec::zero();
        $parts = [];
        foreach ($results as $result) {
            $agreement = $result->agreement;
            if ($agreement->is_temporary) {
                continue; // acting duty is reported, not blended into the primary score
            }
            $span = BigDecimal::of($agreement->effective_from->diffInDays($agreement->effective_to) + 1);
            $total = $total->plus(Dec::of($result->final_score)->multipliedBy($span));
            $days = $days->plus($span);
            $parts[] = ['agreement_id' => $agreement->getKey(), 'days' => (string) $span, 'final_score' => $result->final_score];
        }

        return [
            'score' => $this->settings->prorateTransferResults() ? Dec::str(Dec::div($total, $days)) : null,
            'parts' => $parts,
            'formula' => 'Σ(final score × days in agreement) ÷ Σ days',
        ];
    }

    private function assertReadyForFinalization(PerformanceResult $result): void
    {
        $agreement = $result->agreement;
        $yearEnd = $agreement->reviews()->where('review_type', ReviewType::YearEnd->value)->first();
        if ($yearEnd === null || $yearEnd->status !== ReviewStatus::Completed) {
            throw ValidationException::withMessages(['result' => __('performance.errors.yearend_incomplete')]);
        }
        if ($this->settings->requireMidyearReview() && $agreement->reviews()->where('review_type', ReviewType::MidYear->value)->where('status', ReviewStatus::Completed->value)->doesntExist()) {
            throw ValidationException::withMessages(['result' => __('performance.errors.midyear_incomplete')]);
        }
        if (! ($result->snapshot_json['competency_complete'] ?? true)) {
            throw ValidationException::withMessages(['result' => __('performance.errors.competency_incomplete')]);
        }
        if ($agreement->status !== AgreementStatus::Closed && $result->adjustments()->where('status', AdjustmentStatus::Pending->value)->exists()) {
            throw ValidationException::withMessages(['result' => __('performance.errors.adjustment_pending')]);
        }
    }

    private function assertScore(string $score): void
    {
        $value = Dec::of($score);
        if ($value === null || $value->isNegative() || $value->isGreaterThan(200)) {
            throw ValidationException::withMessages(['adjusted_score' => __('performance.errors.score_range')]);
        }
    }
}

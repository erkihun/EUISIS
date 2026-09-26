<?php

declare(strict_types=1);

namespace App\Services\Performance;

use App\Enums\AuditEventType;
use App\Enums\CommitteeType;
use App\Enums\Performance\AdjustmentStatus;
use App\Enums\Performance\CalibrationStatus;
use App\Enums\Performance\ResultStatus;
use App\Models\GrievanceCommittee;
use App\Models\OrganizationUnit;
use App\Models\PerformanceCalibrationItem;
use App\Models\PerformanceCalibrationSession;
use App\Models\PerformanceCycle;
use App\Models\PerformanceResult;
use App\Models\User;
use App\Services\Performance\Calculation\Dec;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Calibration (docs/epms-workflow.md §6): a panel reviews calculated results
 * across managers/units to reduce inconsistency. No forced distribution —
 * each score is decided on its own merits, with a reason.
 *
 * Panel members come from a reused grievance committee of type
 * performance_calibration. Before/after is kept (item + CALIBRATION
 * adjustment); the manager-stage score is never overwritten.
 */
final class PerformanceCalibrationService
{
    public function __construct(
        private readonly EpmsAccess $access,
        private readonly EpmsAudit $audit,
        private readonly PerformanceResultService $results,
        private readonly EmployeeScoreCalculator $calculator,
    ) {}

    /** @param array<string, mixed> $data */
    public function createSession(PerformanceCycle $cycle, array $data, User $actor): PerformanceCalibrationSession
    {
        $this->access->authorize($this->access->inScope($actor, 'performance_calibration.manage', $data['organization_id'] ?? null));

        // An open cycle of this organization, or a city-wide cycle (committees always belong to one organization).
        $cycleFits = ($cycle->organization_id === null || $cycle->organization_id === $data['organization_id'])
            && ! PerformanceCycleService::isReadOnly($cycle);
        if (! $cycleFits) {
            throw ValidationException::withMessages(['cycle_id' => __('performance.errors.calibration_cycle_invalid')]);
        }
        if (! empty($data['organization_unit_id'])
            && ! OrganizationUnit::query()->whereKey($data['organization_unit_id'])->where('organization_id', $data['organization_id'])->exists()) {
            throw ValidationException::withMessages(['organization_unit_id' => __('performance.errors.unit_outside_organization')]);
        }

        if (! empty($data['committee_id'])) {
            $committee = GrievanceCommittee::query()->find($data['committee_id']);
            if ($committee === null || $committee->committee_type !== CommitteeType::PerformanceCalibration || $committee->organization_id !== $data['organization_id']) {
                throw ValidationException::withMessages(['committee_id' => __('performance.errors.committee_invalid')]);
            }
        }

        $session = new PerformanceCalibrationSession([...$data, 'cycle_id' => $cycle->getKey(), 'created_by' => $actor->getKey()]);
        $session->forceFill(['status' => CalibrationStatus::Draft])->save();

        return $session;
    }

    /** @param list<string> $resultIds */
    public function addResults(PerformanceCalibrationSession $session, array $resultIds, User $actor): int
    {
        $this->assertManager($session, $actor);
        $added = 0;
        foreach (PerformanceResult::query()->whereIn('id', $resultIds)->where('is_current', true)->get() as $result) {
            $inScope = $result->cycle_id === $session->cycle_id && $result->organization_id === $session->organization_id
                && ($session->organization_unit_id === null || $result->organization_unit_id === $session->organization_unit_id);
            if (! $inScope || ! in_array($result->status, [ResultStatus::Calculated, ResultStatus::PendingCalibration], true)) {
                throw ValidationException::withMessages(['result_ids' => __('performance.errors.result_not_calibratable')]);
            }
            PerformanceCalibrationItem::query()->firstOrCreate(
                ['session_id' => $session->getKey(), 'result_id' => $result->getKey()],
                ['employee_id' => $result->employee_id, 'manager_score' => $result->final_score],
            );
            $added++;
        }
        $session->forceFill(['status' => CalibrationStatus::InProgress])->save();

        return $added;
    }

    public function decide(PerformanceCalibrationItem $item, string $score, string $reason, User $actor): PerformanceCalibrationItem
    {
        $session = $item->session;
        $member = $this->access->isCommitteeMember($actor, $session->committee);
        $this->access->authorize($actor->can('performance_calibration.manage') && ($member || $session->committee_id === null && $this->access->inScope($actor, 'performance_calibration.manage', $session->organization_id)));
        if ($session->status === CalibrationStatus::Finalized) {
            throw ValidationException::withMessages(['session' => __('performance.errors.session_final')]);
        }
        $value = Dec::of($score);
        if ($value === null || $value->isNegative() || $value->isGreaterThan(200)) {
            throw ValidationException::withMessages(['calibrated_score' => __('performance.errors.score_range')]);
        }
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => __('performance.errors.reason_required')]);
        }

        $item->forceFill(['proposed_score' => $item->proposed_score ?? $score, 'calibrated_score' => Dec::str($value), 'reason' => $reason, 'decided_by' => $actor->getKey(), 'decided_at' => now()])->save();
        $this->audit->record(AuditEventType::PerformanceCalibrated, $actor, $item->result, ['calibrated_score' => $item->calibrated_score], ['manager_score' => $item->manager_score], $reason);

        return $item;
    }

    public function finalize(PerformanceCalibrationSession $session, User $actor): PerformanceCalibrationSession
    {
        $this->access->authorize($this->access->inScope($actor, 'performance_calibration.finalize', $session->organization_id));
        if ($session->status === CalibrationStatus::Finalized) {
            throw ValidationException::withMessages(['session' => __('performance.errors.session_final')]);
        }
        $items = $session->items()->with('result')->get();
        if ($items->isEmpty()) {
            throw ValidationException::withMessages(['session' => __('performance.errors.session_empty')]);
        }

        return DB::transaction(function () use ($session, $items, $actor): PerformanceCalibrationSession {
            foreach ($items as $item) {
                /** @var PerformanceResult $result */
                $result = PerformanceResult::query()->whereKey($item->result_id)->lockForUpdate()->firstOrFail();
                if (PerformanceResultService::isFinal($result)) {
                    continue;
                }
                // Undecided = confirmed as it stood.
                $calibrated = $item->calibrated_score ?? $item->manager_score;
                $item->forceFill(['calibrated_score' => $calibrated, 'decided_by' => $item->decided_by ?? $actor->getKey(), 'decided_at' => $item->decided_at ?? now()])->save();

                $result->adjustments()->create([
                    'adjustment_type' => 'CALIBRATION',
                    'original_score' => $item->manager_score,
                    'adjusted_score' => $calibrated,
                    'reason' => $item->reason ?? 'confirmed at calibration',
                    'requested_by' => $item->decided_by,
                ])->forceFill(['status' => AdjustmentStatus::Approved, 'decided_by' => $actor->getKey(), 'decided_at' => now()])->save();

                $rating = $this->calculator->ratingFor((string) $calibrated);
                $result->forceFill([
                    'calibrated_score' => $calibrated,
                    'final_score' => $calibrated,
                    'rating_band_id' => $rating['band_id'],
                    'rating_label_en' => $rating['label_en'],
                    'rating_label_am' => $rating['label_am'],
                    'status' => ResultStatus::Calculated,
                ])->save();

                $this->results->freeze($result, $actor, [
                    'session_id' => $session->getKey(), 'manager_score' => (string) $item->manager_score, 'calibrated_score' => (string) $calibrated, 'reason' => $item->reason,
                ]);
            }

            $session->forceFill(['status' => CalibrationStatus::Finalized, 'finalized_by' => $actor->getKey(), 'finalized_at' => now()])->save();
            $this->audit->record(AuditEventType::PerformanceCalibrated, $actor, $session, ['finalized_items' => $items->count()]);

            return $session;
        });
    }

    private function assertManager(PerformanceCalibrationSession $session, User $actor): void
    {
        $this->access->authorize($this->access->inScope($actor, 'performance_calibration.manage', $session->organization_id));
        if ($session->status === CalibrationStatus::Finalized) {
            throw ValidationException::withMessages(['session' => __('performance.errors.session_final')]);
        }
    }
}

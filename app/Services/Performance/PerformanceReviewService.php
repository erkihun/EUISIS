<?php

declare(strict_types=1);

namespace App\Services\Performance;

use App\Enums\AuditEventType;
use App\Enums\Performance\AgreementStatus;
use App\Enums\Performance\CycleStatus;
use App\Enums\Performance\KpiHealth;
use App\Enums\Performance\ReviewStatus;
use App\Enums\Performance\ReviewType;
use App\Models\EmployeeCompetencyAssessment;
use App\Models\EmployeePerformanceAgreement;
use App\Models\PerformanceCheckin;
use App\Models\PerformanceReview;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Check-ins, mid-year and year-end reviews (docs/epms-workflow.md §4).
 *
 * Check-ins are developmental conversations and never produce a score.
 * Reviews: the employee writes a self-assessment (never a score); the
 * manager verifies progress, comments, rates competencies and completes or
 * returns. The official score comes only from PerformanceResultService.
 */
final class PerformanceReviewService
{
    private const OPEN_AGREEMENTS = [AgreementStatus::Active, AgreementStatus::UnderReview];

    public function __construct(
        private readonly EpmsAccess $access,
        private readonly EpmsAudit $audit,
        private readonly EpmsSettings $settings,
        private readonly PerformanceNotifier $notifier,
    ) {}

    /** @param array<string, mixed> $data */
    public function addCheckin(EmployeePerformanceAgreement $agreement, array $data, User $actor): PerformanceCheckin
    {
        $this->access->authorize($actor->can('performance_checkins.manage') && $this->access->isManagerOf($actor, $agreement));
        $this->assertOpen($agreement);

        return $agreement->checkins()->create([
            ...$data,
            'employee_id' => $agreement->employee_id,
            'manager_user_id' => $actor->getKey(),
            'progress_status' => $data['progress_status'] ?? KpiHealth::OnTrack->value,
            'created_by' => $actor->getKey(),
        ]);
    }

    /** The employee adds their side to a check-in (never the manager's fields). */
    public function employeeCheckinNote(PerformanceCheckin $checkin, array $data, User $actor): PerformanceCheckin
    {
        $this->access->authorize($this->access->isOwn($actor, $checkin->agreement));
        $this->assertOpen($checkin->agreement);
        $checkin->fill(array_intersect_key($data, array_flip(['employee_summary', 'blockers', 'support_required', 'learning_needs'])))->save();

        return $checkin;
    }

    public function reviewFor(EmployeePerformanceAgreement $agreement, ReviewType $type): PerformanceReview
    {
        return PerformanceReview::query()->firstOrCreate(['agreement_id' => $agreement->getKey(), 'review_type' => $type->value]);
    }

    /** @param array<string, mixed> $data */
    public function employeeSubmit(EmployeePerformanceAgreement $agreement, ReviewType $type, array $data, User $actor): PerformanceReview
    {
        $this->access->authorize($this->access->isOwn($actor, $agreement) && $actor->can('performance_reviews.self_assess'));
        $this->assertOpen($agreement);
        $this->assertWindow($agreement, $type);

        $review = $this->reviewFor($agreement, $type);
        if (! in_array($review->status, [ReviewStatus::Draft, ReviewStatus::Returned], true)) {
            throw ValidationException::withMessages(['review' => __('performance.errors.stale')]);
        }

        $review->fill(array_intersect_key($data, array_flip(['employee_self_assessment', 'achievements', 'challenges', 'contributions', 'development_needs'])));
        $review->forceFill(['status' => ReviewStatus::EmployeeSubmitted, 'employee_submitted_at' => now(), 'return_reason' => null])->save();

        // Self-ratings of competencies (the manager's rating is the one that counts).
        foreach ((array) ($data['self_ratings'] ?? []) as $competencyId => $rating) {
            EmployeeCompetencyAssessment::query()->where('agreement_id', $agreement->getKey())->where('competency_id', $competencyId)
                ->update(['self_rating' => (int) $rating]);
        }

        $this->audit->record(AuditEventType::PerformanceReviewChanged, $actor, $review, ['type' => $type->value, 'status' => ReviewStatus::EmployeeSubmitted->value]);
        $this->notifier->toUser($agreement->manager, 'review_submitted', '/performance/agreements/'.$agreement->getKey());

        return $review;
    }

    /** @param array<string, mixed> $data manager_comment, manager_private_note, at_risk_item_ids, improvement_actions, ratings[competency_id => 1..N] */
    public function managerComplete(EmployeePerformanceAgreement $agreement, ReviewType $type, array $data, User $actor): PerformanceReview
    {
        $this->access->authorize($actor->can('performance_reviews.manage') && $this->access->isManagerOf($actor, $agreement));
        $this->assertOpen($agreement);

        $review = $this->reviewFor($agreement, $type);
        $selfRequired = $type === ReviewType::YearEnd ? $this->settings->requireYearendSelfAssessment() : true;
        $allowed = $selfRequired ? [ReviewStatus::EmployeeSubmitted, ReviewStatus::ManagerReview] : [ReviewStatus::Draft, ReviewStatus::EmployeeSubmitted, ReviewStatus::ManagerReview, ReviewStatus::Returned];
        if (! in_array($review->status, $allowed, true)) {
            throw ValidationException::withMessages(['review' => __('performance.errors.self_assessment_first')]);
        }

        $itemIds = $agreement->items()->pluck('id')->all();
        $atRisk = array_values(array_intersect((array) ($data['at_risk_item_ids'] ?? []), $itemIds));

        $review->fill([
            'manager_comment' => $data['manager_comment'] ?? null,
            'manager_private_note' => $data['manager_private_note'] ?? null,
            'at_risk_item_ids' => $atRisk,
            'improvement_actions' => $data['improvement_actions'] ?? null,
        ]);
        $review->forceFill(['status' => ReviewStatus::Completed, 'manager_user_id' => $actor->getKey(), 'manager_reviewed_at' => now(), 'completed_at' => now()])->save();

        if ($type === ReviewType::YearEnd) {
            $max = 10;
            foreach ((array) ($data['ratings'] ?? []) as $competencyId => $rating) {
                $rating = (int) $rating;
                if ($rating < 1 || $rating > $max) {
                    throw ValidationException::withMessages(["ratings.{$competencyId}" => __('performance.errors.rating_range')]);
                }
                EmployeeCompetencyAssessment::query()->where('agreement_id', $agreement->getKey())->where('competency_id', $competencyId)
                    ->update(['manager_rating' => $rating, 'rated_by' => $actor->getKey(), 'rated_at' => now()]);
            }
        }

        $this->audit->record(AuditEventType::PerformanceReviewChanged, $actor, $review, ['type' => $type->value, 'status' => ReviewStatus::Completed->value, 'at_risk_items' => count($atRisk)]);

        return $review;
    }

    public function managerReturn(EmployeePerformanceAgreement $agreement, ReviewType $type, string $reason, User $actor): PerformanceReview
    {
        $this->access->authorize($actor->can('performance_reviews.manage') && $this->access->isManagerOf($actor, $agreement));
        $review = $this->reviewFor($agreement, $type);
        if ($review->status !== ReviewStatus::EmployeeSubmitted) {
            throw ValidationException::withMessages(['review' => __('performance.errors.stale')]);
        }
        $review->forceFill(['status' => ReviewStatus::Returned, 'return_reason' => $reason])->save();
        $this->audit->record(AuditEventType::PerformanceReviewChanged, $actor, $review, ['type' => $type->value, 'status' => ReviewStatus::Returned->value], null, $reason);
        $this->notifier->toEmployee($agreement->employee, 'review_returned');

        return $review;
    }

    private function assertOpen(EmployeePerformanceAgreement $agreement): void
    {
        if (! in_array($agreement->status, self::OPEN_AGREEMENTS, true)) {
            throw ValidationException::withMessages(['agreement' => __('performance.errors.agreement_not_active')]);
        }
    }

    /**
     * Whether to prompt the employee for this self-assessment now: inside the
     * cycle's window when one is set, otherwise while the cycle is in that
     * review phase. The review itself must still be open (not yet submitted).
     */
    public function selfAssessmentDue(EmployeePerformanceAgreement $agreement, ReviewType $type): bool
    {
        if (! in_array($agreement->status, self::OPEN_AGREEMENTS, true)) {
            return false;
        }
        $review = $agreement->reviews()->where('review_type', $type->value)->first();
        if ($review !== null && $review->status !== ReviewStatus::Draft) {
            return false;
        }

        $cycle = $agreement->cycle;
        [$from, $to] = $this->window($agreement, $type);
        if ($from !== null && $to !== null) {
            return now()->betweenIncluded($from, $to);
        }

        return $cycle->status === ($type === ReviewType::MidYear ? CycleStatus::MidYearReview : CycleStatus::YearEndReview);
    }

    /** Use the same state and date rules for portal actions and submission. */
    public function canSubmitSelfAssessment(EmployeePerformanceAgreement $agreement, ReviewType $type, User $actor): bool
    {
        if (! $this->access->isOwn($actor, $agreement) || ! $actor->can('performance_reviews.self_assess')
            || ! in_array($agreement->status, self::OPEN_AGREEMENTS, true)) {
            return false;
        }
        $review = $agreement->reviews()->where('review_type', $type->value)->first();
        if ($review !== null && ! in_array($review->status, [ReviewStatus::Draft, ReviewStatus::Returned], true)) {
            return false;
        }
        [$from, $to] = $this->window($agreement, $type);

        return $from === null || $to === null || now()->betweenIncluded($from, $to);
    }

    /** Employee submissions respect the cycle's review window when one is set. */
    private function assertWindow(EmployeePerformanceAgreement $agreement, ReviewType $type): void
    {
        [$from, $to] = $this->window($agreement, $type);

        if ($from !== null && $to !== null && ! now()->betweenIncluded($from, $to)) {
            throw ValidationException::withMessages(['review' => __('performance.errors.outside_review_window')]);
        }
    }

    /** @return array{0: ?Carbon, 1: ?Carbon} the cycle's window for this review, whole days */
    private function window(EmployeePerformanceAgreement $agreement, ReviewType $type): array
    {
        $cycle = $agreement->cycle;
        [$from, $to] = $type === ReviewType::MidYear
            ? [$cycle->midyear_review_start_date, $cycle->midyear_review_end_date]
            : [$cycle->yearend_review_start_date, $cycle->yearend_review_end_date];

        return [$from !== null ? Carbon::parse($from)->startOfDay() : null, $to !== null ? Carbon::parse($to)->endOfDay() : null];
    }
}

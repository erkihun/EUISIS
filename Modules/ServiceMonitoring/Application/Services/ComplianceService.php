<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Application\Services;

use Carbon\CarbonImmutable;
use Modules\ServiceMonitoring\Domain\Enums\SubmissionStatus;
use Modules\ServiceMonitoring\Domain\Models\DeadlineExtension;
use Modules\ServiceMonitoring\Domain\Models\NonSubmission;
use Modules\ServiceMonitoring\Domain\Models\Participant;
use Modules\ServiceMonitoring\Domain\Models\ReportingPeriod;
use Modules\ServiceMonitoring\Domain\Models\Submission;

/**
 * Where each expected participant stands for a period:
 *
 *   on_time         accepted, submitted by the deadline
 *   late_extension  accepted or in review, submitted after the deadline under an extension (D6)
 *   in_review       submitted, not yet decided
 *   returned        returned for correction
 *   pending         not submitted, deadline (or an active extension) not passed yet
 *   missing         not submitted and the deadline passed
 *   not_submitted   formally recorded as not submitted (D8)
 *
 * Three queries per period, whatever the number of participants.
 */
class ComplianceService
{
    public const STATUSES = ['on_time', 'late_extension', 'in_review', 'returned', 'pending', 'missing', 'not_submitted'];

    /**
     * @param  iterable<Participant>  $participants
     * @return array<string, array{status: string, late: bool, recordable: bool, submission_id: ?string, submission_status: ?string, revision_no: ?int}>
     */
    public function forPeriod(ReportingPeriod $period, iterable $participants, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $ids = [];
        foreach ($participants as $participant) {
            $ids[] = $participant->id;
        }
        $current = Submission::query()->where('period_id', $period->id)->whereIn('participant_id', $ids)->where('is_current', true)
            ->get(['id', 'participant_id', 'status', 'submitted_late', 'revision_no'])->keyBy('participant_id');
        // A correction draft is the current revision while the accepted one still counts.
        $accepted = Submission::query()->where('period_id', $period->id)->whereIn('participant_id', $ids)->where('status', SubmissionStatus::Accepted->value)
            ->get(['id', 'participant_id', 'submitted_late', 'revision_no'])->keyBy('participant_id');
        $recorded = NonSubmission::query()->where('period_id', $period->id)->whereIn('participant_id', $ids)->pluck('participant_id')->flip();
        $extended = DeadlineExtension::query()->where('period_id', $period->id)->whereIn('participant_id', $ids)->whereNull('revoked_at')
            ->where('due_at', '>=', $now->toDateTimeString())->pluck('participant_id')->flip();
        $pastDeadline = $now->greaterThan($period->submissionDeadline());

        $result = [];
        foreach ($ids as $id) {
            $submission = $current->get($id);
            $acceptedRow = $accepted->get($id);
            $status = match (true) {
                $acceptedRow !== null => $acceptedRow->submitted_late ? 'late_extension' : 'on_time',
                $submission?->status === SubmissionStatus::Submitted => $submission->submitted_late ? 'late_extension' : 'in_review',
                // A report returned and never resubmitted may be recorded as not submitted.
                $recorded->has($id) => 'not_submitted',
                $submission?->status === SubmissionStatus::Returned => 'returned',
                $pastDeadline && ! $extended->has($id) => 'missing',
                default => 'pending',
            };
            $shown = $acceptedRow ?? $submission;
            $result[$id] = [
                'status' => $status,
                'late' => (bool) ($shown?->submitted_late ?? false),
                // May be formally recorded as not submitted (D8): nothing in or through review, or a
                // returned report still not resubmitted once the deadline (and any extension) passed.
                'recordable' => in_array($status, ['missing', 'pending'], true)
                    || ($status === 'returned' && $pastDeadline && ! $extended->has($id)),
                'submission_id' => $shown?->id,
                'submission_status' => $acceptedRow !== null ? SubmissionStatus::Accepted->value : $submission?->status?->value,
                'revision_no' => $shown?->revision_no,
            ];
        }

        return $result;
    }

    /**
     * @param  array<string, array{status: string}>  $rows
     * @return array<string, int>
     */
    public static function tally(array $rows): array
    {
        $tally = array_fill_keys(self::STATUSES, 0);
        foreach ($rows as $row) {
            $tally[$row['status']]++;
        }

        return $tally;
    }
}

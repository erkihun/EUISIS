<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Application\Services;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Modules\ServiceMonitoring\Domain\Models\DeadlineExtension;
use Modules\ServiceMonitoring\Domain\Models\ReportingPeriod;
use Modules\ServiceMonitoring\Domain\Models\Submission;

/**
 * Immutability and deadline rules shared by every write: nothing changes in a
 * locked period or on a submission that is not the editable current revision
 * (D9); a first submission after the deadline needs an active extension (D6).
 */
class SubmissionGuard
{
    public function __construct(private readonly FiscalCalendar $calendar) {}

    public function assertWritable(Submission $submission): void
    {
        if ($submission->period->isLocked()) {
            throw ValidationException::withMessages(['period' => __('service-monitoring.validation.period_locked')]);
        }
        if (! $submission->isEditable()) {
            throw ValidationException::withMessages(['submission' => __('service-monitoring.validation.not_editable')]);
        }
    }

    public function assertPeriodWritable(ReportingPeriod $period): void
    {
        if ($period->isLocked()) {
            throw ValidationException::withMessages(['period' => __('service-monitoring.validation.period_locked')]);
        }
    }

    public function assertOpened(ReportingPeriod $period, ?CarbonImmutable $now = null): void
    {
        if ($this->calendar->today($now) < $period->opens_on->toDateString()) {
            throw ValidationException::withMessages(['period' => __('service-monitoring.validation.not_open')]);
        }
    }

    public function activeExtension(ReportingPeriod $period, string $participantId, ?CarbonImmutable $now = null): ?DeadlineExtension
    {
        return DeadlineExtension::query()
            ->where('period_id', $period->id)->where('participant_id', $participantId)
            ->whereNull('revoked_at')->where('due_at', '>=', ($now ?? CarbonImmutable::now())->toDateTimeString())
            ->latest('due_at')->first();
    }

    /**
     * Whether a (first) submission is allowed now, and under which extension.
     * Returns null when on time, the extension when late under one, and
     * throws when late without one ("late reports are not accepted").
     */
    public function lateUnder(ReportingPeriod $period, string $participantId, ?CarbonImmutable $now = null): ?DeadlineExtension
    {
        $now ??= CarbonImmutable::now();
        if ($now->lessThanOrEqualTo($period->submissionDeadline())) {
            return null;
        }
        $extension = $this->activeExtension($period, $participantId, $now);
        if ($extension === null) {
            throw ValidationException::withMessages(['deadline' => __('service-monitoring.validation.deadline_passed', ['date' => $this->calendar->display($period->submission_due_on->toDateString())])]);
        }

        return $extension;
    }
}

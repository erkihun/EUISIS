<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Application\Actions;

use App\Enums\AuditEventType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\ServiceMonitoring\Application\Services\ServiceMonitoringAudit;
use Modules\ServiceMonitoring\Application\Services\SubmissionGuard;
use Modules\ServiceMonitoring\Domain\Models\DeadlineExtension;
use Modules\ServiceMonitoring\Domain\Models\Participant;
use Modules\ServiceMonitoring\Domain\Models\ReportingPeriod;

/** A time-boxed extension for one participant and one period, with a mandatory reason (D6). Audited and revocable. */
final readonly class GrantExtension
{
    public function __construct(private SubmissionGuard $guard, private ServiceMonitoringAudit $audit) {}

    public function execute(ReportingPeriod $period, Participant $participant, CarbonImmutable $dueAt, string $reason, User $actor): DeadlineExtension
    {
        $this->guard->assertPeriodWritable($period);
        if ($dueAt->lessThanOrEqualTo($period->submissionDeadline())) {
            throw ValidationException::withMessages(['due_at' => __('service-monitoring.validation.extension_after_due')]);
        }

        return DB::transaction(function () use ($period, $participant, $dueAt, $reason, $actor): DeadlineExtension {
            if ($this->guard->activeExtension($period, $participant->id) !== null) {
                throw ValidationException::withMessages(['participant_id' => __('service-monitoring.validation.extension_active')]);
            }
            $extension = DeadlineExtension::query()->create([
                'period_id' => $period->id,
                'participant_id' => $participant->id,
                'due_at' => $dueAt,
                'reason' => $reason,
                'granted_by' => $actor->id,
            ]);
            $this->audit->record(AuditEventType::ServiceMonitoringExtensionGranted, $actor, $extension, $participant->organization_id,
                ['period_id' => $period->id, 'participant_id' => $participant->id, 'due_at' => $dueAt->toIso8601String()], $reason);

            return $extension;
        });
    }
}

<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Application\Actions;

use App\Enums\AuditEventType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\ServiceMonitoring\Application\Services\ServiceMonitoringAudit;
use Modules\ServiceMonitoring\Domain\Models\ReportingPeriod;

/** The coordinator moves a period's dates (D5). The quarter's own boundaries never move. */
final readonly class UpdatePeriodSchedule
{
    public const FIELDS = ['opens_on', 'submission_due_on', 'subcity_review_due_on', 'city_review_due_on'];

    public function __construct(private ServiceMonitoringAudit $audit) {}

    /** @param array{opens_on: string, submission_due_on: string, subcity_review_due_on: string, city_review_due_on: string} $schedule */
    public function execute(ReportingPeriod $period, array $schedule, User $actor): ReportingPeriod
    {
        if ($period->isLocked()) {
            throw ValidationException::withMessages(['period' => __('service-monitoring.validation.period_locked')]);
        }

        return DB::transaction(function () use ($period, $schedule, $actor): ReportingPeriod {
            $old = collect(self::FIELDS)->mapWithKeys(fn (string $f): array => [$f => $period->{$f}?->toDateString()])->all();
            $period->fill(array_intersect_key($schedule, array_flip(self::FIELDS)));
            $period->status = $period->effectiveStatus();
            $period->save();

            $this->audit->record(AuditEventType::ServiceMonitoringScheduleUpdated, $actor, $period, null,
                array_intersect_key($schedule, array_flip(self::FIELDS)), old: $old);

            return $period;
        });
    }
}

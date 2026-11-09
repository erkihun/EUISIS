<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Application\Actions;

use App\Enums\AuditEventType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\ServiceMonitoring\Application\Services\ConsolidationService;
use Modules\ServiceMonitoring\Application\Services\ParticipantResolver;
use Modules\ServiceMonitoring\Application\Services\ServiceMonitoringAudit;
use Modules\ServiceMonitoring\Domain\Enums\PeriodStatus;
use Modules\ServiceMonitoring\Domain\Enums\SnapshotKind;
use Modules\ServiceMonitoring\Domain\Models\Certification;
use Modules\ServiceMonitoring\Domain\Models\ReportingPeriod;

/**
 * The coordinator locks a period (D8): every sub-city must have certified;
 * the city consolidation is stored as an immutable snapshot (D24) and from
 * then on every report of the period reads from it, and nothing in the
 * period can change until it is reopened.
 */
final readonly class LockPeriod
{
    public function __construct(
        private ParticipantResolver $participants,
        private ConsolidationService $consolidation,
        private ServiceMonitoringAudit $audit,
    ) {}

    public function execute(ReportingPeriod $period, User $actor): ReportingPeriod
    {
        if ($period->isLocked()) {
            throw ValidationException::withMessages(['period' => __('service-monitoring.validation.period_locked')]);
        }
        if ($period->effectiveStatus() === PeriodStatus::Scheduled) {
            throw ValidationException::withMessages(['period' => __('service-monitoring.validation.lock_not_open')]);
        }
        $certified = Certification::query()->where('period_id', $period->id)->pluck('participant_id')->map(fn ($id): string => (string) $id)->all();
        $pending = $this->participants->subCities($period)->reject(fn ($p): bool => in_array($p->id, $certified, true));
        if ($pending->isNotEmpty()) {
            throw ValidationException::withMessages(['period' => __('service-monitoring.validation.lock_pending', [
                'names' => $pending->map(fn ($p): string => (string) ($p->organization?->name_am ?: $p->organization?->name_en))->implode(', '),
            ])]);
        }

        return DB::transaction(function () use ($period, $actor): ReportingPeriod {
            $snapshot = $this->consolidation->storeSnapshot($period, SnapshotKind::PeriodLock, null, $actor);
            $period->fill(['status' => PeriodStatus::Locked, 'locked_at' => now(), 'locked_by' => $actor->id])->save();
            $this->consolidation->forget();
            $this->audit->record(AuditEventType::ServiceMonitoringPeriodLocked, $actor, $period, null,
                ['snapshot_id' => $snapshot->id, 'checksum' => $snapshot->checksum, 'participants' => count($snapshot->payload['participants'] ?? [])]);

            return $period;
        });
    }
}

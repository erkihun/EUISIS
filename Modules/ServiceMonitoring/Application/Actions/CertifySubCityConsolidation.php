<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Application\Actions;

use App\Enums\AuditEventType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\ServiceMonitoring\Application\Services\ComplianceService;
use Modules\ServiceMonitoring\Application\Services\ConsolidationService;
use Modules\ServiceMonitoring\Application\Services\ParticipantResolver;
use Modules\ServiceMonitoring\Application\Services\ServiceMonitoringAudit;
use Modules\ServiceMonitoring\Application\Services\SubmissionGuard;
use Modules\ServiceMonitoring\Domain\Enums\SnapshotKind;
use Modules\ServiceMonitoring\Domain\Models\Certification;
use Modules\ServiceMonitoring\Domain\Models\Participant;
use Modules\ServiceMonitoring\Domain\Models\ReportingPeriod;

/**
 * A sub-city certifies its consolidation for a period (D8) once its own
 * report and every woreda's report is accepted, or the woreda is recorded as
 * not submitted. An immutable snapshot of the consolidation is stored (D24).
 * Certifying again (after a reopen) points the certification at a new snapshot.
 */
final readonly class CertifySubCityConsolidation
{
    public function __construct(
        private SubmissionGuard $guard,
        private ParticipantResolver $participants,
        private ComplianceService $compliance,
        private ConsolidationService $consolidation,
        private ServiceMonitoringAudit $audit,
    ) {}

    public function execute(ReportingPeriod $period, Participant $subCity, User $actor): Certification
    {
        $this->guard->assertPeriodWritable($period);
        $own = $this->compliance->forPeriod($period, [$subCity])[$subCity->id];
        if (! in_array($own['status'], ['on_time', 'late_extension'], true) || $own['submission_status'] !== 'accepted') {
            throw ValidationException::withMessages(['certification' => __('service-monitoring.validation.certify_own')]);
        }
        $woredas = $this->participants->woredasOf($subCity, $period);
        $pending = collect($this->compliance->forPeriod($period, $woredas))
            ->filter(fn (array $row): bool => ! ($row['submission_status'] === 'accepted' || $row['status'] === 'not_submitted'))
            ->keys();
        if ($pending->isNotEmpty()) {
            $names = $woredas->whereIn('id', $pending->all())->map(fn (Participant $p): string => (string) ($p->organization?->name_am ?: $p->organization?->name_en))->implode(', ');
            throw ValidationException::withMessages(['certification' => __('service-monitoring.validation.certify_pending', ['names' => $names])]);
        }

        return DB::transaction(function () use ($period, $subCity, $actor): Certification {
            $snapshot = $this->consolidation->storeSnapshot($period, SnapshotKind::SubCityCertification, $subCity, $actor, $this->consolidation->subCityParticipantIds($subCity));
            $certification = Certification::query()->updateOrCreate(
                ['period_id' => $period->id, 'participant_id' => $subCity->id],
                ['certified_by' => $actor->id, 'certified_at' => now(), 'snapshot_id' => $snapshot->id],
            );
            $this->audit->record(AuditEventType::ServiceMonitoringCertified, $actor, $certification, $subCity->organization_id,
                ['period_id' => $period->id, 'snapshot_id' => $snapshot->id, 'checksum' => $snapshot->checksum]);

            return $certification;
        });
    }
}

<?php

declare(strict_types=1);

namespace Modules\Reporting\Application\UseCases;

use Modules\Reporting\Application\Data\ActorContext;
use Modules\Reporting\Application\Ports\AuditRecorder;
use Modules\Reporting\Application\Ports\OrganizationDirectory;
use Modules\Reporting\Application\Support\ReportingAccessDenied;
use Modules\Reporting\Application\Support\ReportingGuard;
use Modules\Reporting\Application\Support\ReportingSettings;
use Modules\Reporting\Contracts\Data\ReportingRoles;
use Modules\Reporting\Domain\Workflow\WorkflowViolation;
use Modules\Reporting\Infrastructure\Persistence\Models\MonitoringUnit;

/**
 * The work unit that follows up reports in an institution.
 *
 * An institution designates one of its existing, active work units. The
 * people placed in that unit (or in a unit beneath it) then reach the
 * institution's reports in this module, and the notices about reports the
 * institution receives go to those of them who hold the permission. What
 * each of them may do is still decided by their Reporting permissions.
 */
final readonly class ManageMonitoringUnits
{
    public function __construct(
        private OrganizationDirectory $organizations,
        private AuditRecorder $audit,
        private ReportingSettings $settings,
    ) {}

    public function designate(ActorContext $actor, string $organizationId, string $unitId): MonitoringUnit
    {
        $this->authorize($actor, $organizationId);
        $unit = $this->organizations->units($organizationId)[$unitId] ?? throw new WorkflowViolation('unknown_unit');

        $before = MonitoringUnit::query()->where('organization_id', $organizationId)->value('organization_unit_id');
        $record = MonitoringUnit::query()->updateOrCreate(['organization_id' => $organizationId], [
            'organization_unit_id' => $unit->id, 'unit_code' => $unit->code, 'unit_name_en' => $unit->nameEn, 'unit_name_am' => $unit->nameAm,
            'designated_by' => $actor->userId,
        ]);
        $this->audit->record('settings_changed', $actor, 'reporting.monitoring_unit', $record->id, $organizationId, ['before' => ['organization_unit_id' => $before], 'after' => ['organization_unit_id' => $unit->id]]);

        return $record;
    }

    public function remove(ActorContext $actor, string $organizationId): void
    {
        $this->authorize($actor, $organizationId);
        $record = MonitoringUnit::query()->where('organization_id', $organizationId)->first();
        if ($record === null) {
            return;
        }
        $record->delete();
        $this->audit->record('settings_changed', $actor, 'reporting.monitoring_unit', $record->id, $organizationId, ['before' => ['organization_unit_id' => $record->organization_unit_id], 'after' => ['organization_unit_id' => null]]);
    }

    /**
     * The designations of the institutions the actor reaches, with the institution named.
     *
     * @return list<array<string, mixed>>
     */
    public function list(ActorContext $actor): array
    {
        $records = MonitoringUnit::query()->when(! $actor->unrestricted, fn ($q) => $q->whereIn('organization_id', $actor->organizationIds))->orderBy('unit_code')->limit(500)->get();
        $names = $this->organizations->findMany($records->pluck('organization_id')->all());

        return $records->map(fn (MonitoringUnit $record): array => [
            'organization' => isset($names[$record->organization_id]) ? $names[$record->organization_id]->toArray() : ['id' => $record->organization_id, 'code' => '?', 'name_en' => null, 'name_am' => null],
            'unit' => ['id' => $record->organization_unit_id, 'code' => $record->unit_code, 'name_en' => $record->unit_name_en, 'name_am' => $record->unit_name_am],
        ])->sortBy('organization.code')->values()->all();
    }

    private function authorize(ActorContext $actor, string $organizationId): void
    {
        ReportingGuard::require($actor, ReportingRoles::MANAGE_SETTINGS);
        $this->settings->assertWritable();
        if (! $actor->reaches($organizationId)) {
            throw new ReportingAccessDenied(ReportingRoles::MANAGE_SETTINGS);
        }
    }
}

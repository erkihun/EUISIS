<?php

declare(strict_types=1);

namespace Modules\Reporting\Application\UseCases;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Modules\Reporting\Application\Data\ActorContext;
use Modules\Reporting\Application\Ports\AuditRecorder;
use Modules\Reporting\Application\Ports\OrganizationDirectory;
use Modules\Reporting\Application\Ports\ReportingAccessResolver;
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
    /** A unit's card lists at most this many people. */
    public const STAFF_SHOWN = 60;

    public function __construct(
        private OrganizationDirectory $organizations,
        private ReportingAccessResolver $access,
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
     * One page of the designations of the institutions the actor reaches,
     * each with the institution named and the people placed in the unit.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function page(ActorContext $actor, string $search = '', int $perPage = 12): LengthAwarePaginator
    {
        $like = '%'.addcslashes(mb_strtolower($search), '%_\\').'%';
        $page = $this->reachable($actor)
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w->whereRaw('LOWER(unit_name_en) LIKE ?', [$like])
                ->orWhere('unit_name_am', 'like', $like)->orWhereRaw('LOWER(unit_code) LIKE ?', [$like])))
            ->orderBy('unit_code')->orderBy('id')->paginate(max(5, min($perPage, 50)));
        $names = $this->organizations->findMany($page->getCollection()->pluck('organization_id')->all());

        return $page->through(fn (MonitoringUnit $record): array => [
            'organization' => isset($names[$record->organization_id]) ? $names[$record->organization_id]->toArray() : ['id' => $record->organization_id, 'code' => '?', 'name_en' => null, 'name_am' => null],
            'unit' => ['id' => $record->organization_unit_id, 'code' => $record->unit_code, 'name_en' => $record->unit_name_en, 'name_am' => $record->unit_name_am],
            // The unit was closed or moved since it was named: the designation should be changed.
            'unit_active' => isset($this->organizations->units($record->organization_id)[$record->organization_unit_id]),
            'designated_at' => $record->updated_at?->toIso8601String(),
            'staff' => array_map(fn (array $member): array => [
                'id' => $member['user']->id, 'name' => $member['user']->name, 'email' => $member['user']->email,
                'role' => match (true) {
                    in_array(ReportingRoles::APPROVE, $member['permissions'], true) => 'approve',
                    in_array(ReportingRoles::REVIEW, $member['permissions'], true) => 'review',
                    $member['permissions'] !== [] => 'view',
                    default => 'none',
                },
            ], $this->access->staffOf($record->organization_unit_id, self::STAFF_SHOWN)),
        ]);
    }

    /**
     * @return array{designated: int, institutions: int}
     */
    public function summary(ActorContext $actor): array
    {
        return [
            'designated' => $this->reachable($actor)->count(),
            'institutions' => $this->organizations->page($actor, 1, 1)['total'],
        ];
    }

    /** @return Builder<MonitoringUnit> */
    private function reachable(ActorContext $actor): Builder
    {
        return MonitoringUnit::query()->when(! $actor->unrestricted, fn (Builder $q) => $q->whereIn('organization_id', $actor->organizationIds));
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

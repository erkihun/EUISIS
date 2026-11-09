<?php

declare(strict_types=1);

namespace App\Services\OrganizationDesign;

use App\Enums\OrganizationDesignStatus;
use App\Models\JobDescription;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Models\PositionProfile;
use App\Models\PositionService;
use App\Models\PositionServiceTask;
use App\Models\PositionWorkLink;
use App\Models\ProcessTask;
use App\Models\Service;
use App\Models\UnitResponsibility;

/**
 * Classifies the records that existed before the design (brief §6, §8):
 * units, positions and EPMS position services of one organization, each as
 * FULLY_MAPPED, PARTIALLY_MAPPED, UNMAPPED_LEGACY or INVALID_REFERENCE.
 * Read-only: nothing is linked, repaired or deleted here. Records without
 * historical evidence stay UNMAPPED_LEGACY and remain operational.
 */
class LegacyMappingService
{
    public const STATUSES = ['FULLY_MAPPED', 'PARTIALLY_MAPPED', 'UNMAPPED_LEGACY', 'INVALID_REFERENCE'];

    /** @return array{units: list<array<string, mixed>>, positions: list<array<string, mixed>>, position_services: list<array<string, mixed>>, summary: array<string, array<string, int>>} */
    public function classify(string $organizationId): array
    {
        $today = now()->toDateString();
        $units = OrganizationUnit::query()->where('organization_id', $organizationId)->whereIn('status', ['active', 'draft'])->orderBy('code')->limit(5000)->get(['id', 'code', 'name_en', 'name_am', 'parent_unit_id', 'organization_unit_type_id', 'status']);
        $unitIds = $units->pluck('id')->flip();
        $responsibleUnits = UnitResponsibility::query()->where('organization_id', $organizationId)->whereNotNull('organization_unit_id')->distinct()->pluck('organization_unit_id')->flip();
        $positions = Position::query()->where('organization_id', $organizationId)->where('is_active', true)->orderBy('job_position_code')->limit(20000)->get(['id', 'job_position_code', 'title_en', 'title_am', 'organization_unit_id', 'occupation_id']);
        $positionIds = $positions->pluck('id');
        $links = PositionWorkLink::query()->whereIn('position_id', $positionIds)->get(['position_id', 'object_type', 'object_id']);
        $objects = [];
        foreach (PositionWorkLink::OBJECT_MODELS as $type => $model) {
            $ids = $links->where('object_type', $type)->pluck('object_id')->unique();
            $objects[$type] = $ids->isEmpty() ? collect() : $model::query()->whereIn('id', $ids)->where('organization_id', $organizationId)->pluck('id')->flip();
        }
        $linksByPosition = $links->groupBy('position_id');
        $profiled = PositionProfile::query()->whereIn('position_id', $positionIds)->where('status', OrganizationDesignStatus::Published)->pluck('position_id')->flip();
        $described = JobDescription::query()->whereIn('position_id', $positionIds)->whereIn('status', [OrganizationDesignStatus::Published->value, OrganizationDesignStatus::Superseded->value])
            ->whereDate('effective_from', '<=', $today)->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $today))->pluck('position_id')->flip();

        $positionRows = $positions->map(function (Position $p) use ($unitIds, $linksByPosition, $objects, $profiled, $described): array {
            $own = $linksByPosition->get($p->id) ?? collect();
            $invalid = ($p->organization_unit_id !== null && ! $unitIds->has($p->organization_unit_id)) || $own->contains(fn ($l) => ! ($objects[$l->object_type] ?? collect())->has($l->object_id));
            $checks = ['occupation' => $p->occupation_id !== null, 'work_links' => $own->isNotEmpty(), 'profile' => $profiled->has($p->id), 'job_description' => $described->has($p->id)];

            return ['id' => $p->id, 'code' => $p->job_position_code, 'name_en' => $p->title_en, 'name_am' => $p->title_am, 'unit_id' => $p->organization_unit_id, 'status' => $invalid ? 'INVALID_REFERENCE' : $this->grade($checks), 'checks' => $checks];
        });
        $positionsByUnit = $positionRows->groupBy('unit_id');

        $unitRows = $units->map(function (OrganizationUnit $u) use ($unitIds, $responsibleUnits, $positionsByUnit): array {
            $own = $positionsByUnit->get($u->id) ?? collect();
            $invalid = ($u->parent_unit_id !== null && ! $unitIds->has($u->parent_unit_id)) || $u->organization_unit_type_id === null;
            $checks = ['responsibilities' => $responsibleUnits->has($u->id), 'positions_linked' => $own->isNotEmpty() && $own->every(fn ($p) => $p['checks']['work_links'])];
            if ($own->isEmpty()) {
                $checks['positions_linked'] = $checks['responsibilities'];
            }

            return ['id' => $u->id, 'code' => $u->code, 'name_en' => $u->name_en, 'name_am' => $u->name_am, 'positions' => $own->count(), 'status' => $invalid ? 'INVALID_REFERENCE' : $this->grade($checks), 'checks' => $checks];
        });

        $services = PositionService::query()->where('organization_id', $organizationId)->where('is_active', true)->with('position:id,job_position_code')->orderBy('service_no')->limit(20000)->get();
        $masterServices = Service::query()->whereIn('id', $services->pluck('master_service_id')->filter()->unique())->get(['id', 'organization_id', 'status'])->keyBy('id');
        $tasks = PositionServiceTask::query()->whereIn('position_service_id', $services->pluck('id'))->where('is_active', true)->get(['id', 'position_service_id', 'master_task_id'])->groupBy('position_service_id');
        $masterTasks = ProcessTask::query()->whereIn('id', $tasks->flatten()->pluck('master_task_id')->filter()->unique())->where('organization_id', $organizationId)->pluck('id')->flip();
        $serviceRows = $services->map(function (PositionService $s) use ($organizationId, $masterServices, $tasks, $masterTasks): array {
            $own = $tasks->get($s->id) ?? collect();
            $master = $s->master_service_id ? $masterServices->get($s->master_service_id) : null;
            $invalid = ($s->master_service_id !== null && ($master === null || $master->organization_id !== $organizationId || $master->status === OrganizationDesignStatus::Cancelled))
                || $own->contains(fn ($t) => $t->master_task_id !== null && ! $masterTasks->has($t->master_task_id));
            $mappedTasks = $own->filter(fn ($t) => $t->master_task_id !== null)->count();
            $checks = ['master_service' => $master !== null, 'tasks_mapped' => $own->isNotEmpty() ? $mappedTasks === $own->count() : $master !== null];
            $status = $invalid ? 'INVALID_REFERENCE' : ($own->isNotEmpty() && $mappedTasks > 0 && $mappedTasks < $own->count() && $master !== null ? 'PARTIALLY_MAPPED' : $this->grade($checks));

            return ['id' => $s->id, 'code' => $s->service_no, 'name_en' => $s->name_en, 'name_am' => $s->name_am, 'position_code' => $s->position?->job_position_code, 'tasks' => $own->count(), 'tasks_mapped' => $mappedTasks, 'master_service_id' => $s->master_service_id, 'status' => $status, 'checks' => $checks];
        });

        $summary = fn ($rows) => array_merge(array_fill_keys(self::STATUSES, 0), $rows->countBy('status')->all());

        return [
            'units' => $unitRows->values()->all(),
            'positions' => $positionRows->values()->all(),
            'position_services' => $serviceRows->values()->all(),
            'summary' => ['units' => $summary($unitRows), 'positions' => $summary($positionRows), 'position_services' => $summary($serviceRows)],
        ];
    }

    /** @param array<string, bool> $checks */
    private function grade(array $checks): string
    {
        $passed = count(array_filter($checks));

        return $passed === 0 ? 'UNMAPPED_LEGACY' : ($passed === count($checks) ? 'FULLY_MAPPED' : 'PARTIALLY_MAPPED');
    }
}

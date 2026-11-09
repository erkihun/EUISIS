<?php

declare(strict_types=1);

namespace App\Services\OrganizationDesign;

use App\Enums\OrganizationDesignStatus;
use App\Models\InstitutionMandate;
use App\Models\JobDescription;
use App\Models\LegacyBaseline;
use App\Models\LegacyBaselineSnapshot;
use App\Models\ManpowerPlan;
use App\Models\OrganizationFunction;
use App\Models\OrganizationUnit;
use App\Models\OrganizationUnitRelationship;
use App\Models\Position;
use App\Models\PositionEstablishment;
use App\Models\PositionProfile;
use App\Models\Process;
use App\Models\ProcessTask;
use App\Models\Service;

/**
 * Freezes what the organization looks like when a baseline is published:
 * the unit tree with its structural and functional relationships, the
 * positions and their approved establishments, the ids of the published
 * chain records and a count per stage. Plain arrays, bounded by a hard
 * limit per list so one giant institution cannot produce an unbounded row.
 */
class LegacyBaselineSnapshotService
{
    public const MAX_ROWS = 20000;

    /** @return array<string, mixed> */
    public function build(LegacyBaseline $baseline): array
    {
        $id = $baseline->organization_id;
        $published = OrganizationDesignStatus::Published;

        $units = OrganizationUnit::query()->where('organization_id', $id)->orderBy('sort_order')->orderBy('code')->limit(self::MAX_ROWS)
            ->get(['id', 'code', 'name_en', 'name_am', 'unit_type', 'organization_unit_type_id', 'parent_unit_id', 'status', 'effective_from', 'effective_to'])
            ->map(fn (OrganizationUnit $u) => [
                'id' => $u->id, 'code' => $u->code, 'name_en' => $u->name_en, 'name_am' => $u->name_am, 'unit_type' => $u->unit_type, 'unit_type_id' => $u->organization_unit_type_id,
                'parent_unit_id' => $u->parent_unit_id, 'status' => $u->status, 'effective_from' => $u->effective_from?->toDateString(), 'effective_to' => $u->effective_to?->toDateString(),
            ])->all();
        $unitIds = array_column($units, 'id');

        $relationships = OrganizationUnitRelationship::query()->whereIn('source_unit_id', $unitIds)->where('status', 'active')->limit(self::MAX_ROWS)
            ->get(['id', 'source_unit_id', 'target_type', 'target_id', 'relationship_type', 'is_primary', 'effective_from', 'effective_to'])
            ->map(fn (OrganizationUnitRelationship $r) => [
                'source_unit_id' => $r->source_unit_id, 'target_type' => $r->target_type, 'target_id' => $r->target_id, 'relationship_type' => $r->relationship_type,
                'is_primary' => (bool) $r->is_primary, 'effective_from' => $r->effective_from?->toDateString(), 'effective_to' => $r->effective_to?->toDateString(),
            ])->all();

        $positions = Position::query()->where('organization_id', $id)->where('is_active', true)->orderBy('job_position_code')->limit(self::MAX_ROWS)
            ->get(['id', 'job_position_code', 'title_en', 'title_am', 'organization_unit_id', 'occupation_id', 'grade_level'])
            ->map(fn (Position $p) => ['id' => $p->id, 'code' => $p->job_position_code, 'title_en' => $p->title_en, 'title_am' => $p->title_am, 'unit_id' => $p->organization_unit_id, 'occupation_id' => $p->occupation_id, 'grade_level' => $p->grade_level])
            ->all();

        $establishments = PositionEstablishment::query()->where('organization_id', $id)->where('status', 'approved')->limit(self::MAX_ROWS)
            ->get(['id', 'establishment_number', 'position_id', 'organization_unit_id', 'approved_slots', 'effective_from', 'effective_to'])
            ->map(fn (PositionEstablishment $e) => ['id' => $e->id, 'number' => $e->establishment_number, 'position_id' => $e->position_id, 'unit_id' => $e->organization_unit_id, 'approved_slots' => (int) $e->approved_slots, 'effective_from' => $e->effective_from?->toDateString(), 'effective_to' => $e->effective_to?->toDateString()])
            ->all();

        $chain = [
            'mandates' => InstitutionMandate::query()->where('organization_id', $id)->where('status', $published)->pluck('id')->all(),
            'functions' => OrganizationFunction::query()->where('organization_id', $id)->pluck('id')->all(),
            'services' => Service::query()->where('organization_id', $id)->where('status', $published)->pluck('id')->all(),
            'processes' => Process::query()->where('organization_id', $id)->where('status', $published)->pluck('id')->all(),
            'tasks' => ProcessTask::query()->where('organization_id', $id)->where('is_active', true)->pluck('id')->all(),
            'manpower_plans' => ManpowerPlan::query()->where('organization_id', $id)->whereIn('status', [$published, OrganizationDesignStatus::Approved])->pluck('id')->all(),
            'position_profiles' => PositionProfile::query()->where('organization_id', $id)->where('status', $published)->pluck('id')->all(),
            'job_descriptions' => JobDescription::query()->where('organization_id', $id)->where('status', $published)->pluck('id')->all(),
        ];

        $counts = [
            'units' => count($units), 'relationships' => count($relationships), 'positions' => count($positions),
            'approved_slots' => array_sum(array_column($establishments, 'approved_slots')),
            ...array_map(fn (array $ids) => count($ids), $chain),
        ];

        return ['units' => $units, 'relationships' => $relationships, 'positions' => $positions, 'establishments' => $establishments, 'chain' => $chain, 'counts' => $counts];
    }

    public function freeze(LegacyBaseline $baseline): LegacyBaselineSnapshot
    {
        $data = $this->build($baseline);
        $encoded = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        return LegacyBaselineSnapshot::query()->create([
            'baseline_id' => $baseline->getKey(),
            'hierarchy_version_id' => $baseline->hierarchy_version_id,
            'taken_at' => now(),
            ...$data,
            'sha256' => hash('sha256', $encoded),
        ]);
    }
}

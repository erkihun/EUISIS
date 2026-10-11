<?php

declare(strict_types=1);

namespace App\Http\Controllers\OrganizationDesign;

use App\Enums\HierarchyVersionStatus;
use App\Http\Controllers\Controller;
use App\Models\HierarchyVersion;
use App\Models\Organization;
use App\Models\OrganizationDesignStudy;
use App\Models\OrganizationEdge;
use App\Models\OrganizationUnit;
use App\Models\OrganizationUnitRelationship;
use App\Services\OrganizationDesign\OrganizationDesignValidationService;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The design-side view of an organization's structure (brief §40–44): the
 * validation report, the unit tree with its functional relationships, and
 * the published hierarchy versions that place the organization. Read-only;
 * changes go through design studies and the change-request pipeline.
 */
class StructureController extends Controller
{
    public function __construct(private readonly OrganizationDesignValidationService $validation, private readonly OrganizationScopeService $scope) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', OrganizationDesignStudy::class);
        $user = $request->user();
        $organizations = Organization::query()->orderBy('name_en');
        $this->scope->applyOrganizationScope($organizations, $user, 'id');
        $options = $organizations->limit(500)->get(['id', 'code', 'name_en', 'name_am']);
        $organizationId = $request->string('organization_id')->toString() ?: ($options->count() === 1 ? $options->first()->id : null);
        if ($organizationId !== null) {
            $this->authorize('viewMatrix', [OrganizationDesignStudy::class, $organizationId]);
        }

        return Inertia::render('OrganizationDesign/Structure/Index', [
            'organizationId' => $organizationId,
            'organizations' => $options->map->only(['id', 'code', 'name_en', 'name_am']),
            'validation' => $organizationId ? $this->validation->validateStructure($organizationId) : null,
            'units' => $organizationId ? $this->unitTree($organizationId) : [],
            'hierarchyVersions' => $organizationId ? $this->hierarchyVersions($organizationId) : [],
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function unitTree(string $organizationId): array
    {
        $units = OrganizationUnit::query()->where('organization_id', $organizationId)->with('unitType:id,code,name_en,name_am')->orderBy('sort_order')->orderBy('code')->get();
        $positionCounts = \App\Models\Position::query()->whereIn('organization_unit_id', $units->pluck('id'))->where('is_active', true)->selectRaw('organization_unit_id, count(*) as c')->groupBy('organization_unit_id')->pluck('c', 'organization_unit_id');
        $relationships = OrganizationUnitRelationship::query()->whereIn('source_unit_id', $units->pluck('id'))->where('status', 'active')->orderBy('relationship_type')->get();
        $targetUnits = OrganizationUnit::query()->whereIn('id', $relationships->where('target_type', 'organization_unit')->pluck('target_id'))->get(['id', 'code', 'name_en', 'name_am'])->keyBy('id');
        $targetOrganizations = Organization::query()->whereIn('id', $relationships->where('target_type', 'organization')->pluck('target_id'))->get(['id', 'code', 'name_en', 'name_am'])->keyBy('id');
        $relationshipsBySource = $relationships->groupBy('source_unit_id');

        $nodes = [];
        foreach ($units as $unit) {
            $nodes[$unit->id] = [
                'id' => $unit->id, 'code' => $unit->code, 'name_en' => $unit->name_en, 'name_am' => $unit->name_am, 'parent_unit_id' => $unit->parent_unit_id,
                'status' => $unit->status instanceof \BackedEnum ? $unit->status->value : $unit->status,
                'unit_type' => $unit->unitType?->only(['id', 'code', 'name_en', 'name_am']) ?? ($unit->unit_type ? ['code' => $unit->unit_type, 'name_en' => $unit->unit_type, 'name_am' => null] : null),
                'active_positions' => (int) ($positionCounts[$unit->id] ?? 0),
                'relationships' => $relationshipsBySource->get($unit->id, collect())->map(function (OrganizationUnitRelationship $r) use ($targetUnits, $targetOrganizations) {
                    $targetType = $r->target_type instanceof \BackedEnum ? $r->target_type->value : (string) $r->target_type;
                    $target = $targetType === 'organization_unit' ? $targetUnits->get($r->target_id) : ($targetType === 'organization' ? $targetOrganizations->get($r->target_id) : null);

                    return [
                        'id' => $r->id, 'relationship_type' => $r->relationship_type instanceof \BackedEnum ? $r->relationship_type->value : (string) $r->relationship_type,
                        'target_type' => $targetType, 'is_primary' => (bool) $r->is_primary,
                        'target' => $target?->only(['id', 'code', 'name_en', 'name_am']),
                    ];
                })->values()->all(),
                'children' => [],
            ];
        }
        $roots = [];
        foreach ($units as $unit) {
            if ($unit->parent_unit_id !== null && isset($nodes[$unit->parent_unit_id])) {
                $nodes[$unit->parent_unit_id]['children'][] = &$nodes[$unit->id];
            } else {
                $roots[] = &$nodes[$unit->id];
            }
        }

        return $roots;
    }

    /** Published hierarchy versions that place this organization, with its parents and children in each. */
    private function hierarchyVersions(string $organizationId): array
    {
        $edges = OrganizationEdge::query()->where(fn ($q) => $q->where('child_organization_id', $organizationId)->orWhere('parent_organization_id', $organizationId))->get();
        $versions = HierarchyVersion::query()->whereIn('id', $edges->pluck('hierarchy_version_id'))->where('status', HierarchyVersionStatus::Published->value)->orderByDesc('effective_from')->get();
        $organizationIds = $edges->pluck('parent_organization_id')->merge($edges->pluck('child_organization_id'))->unique();
        $organizations = Organization::query()->whereIn('id', $organizationIds)->get(['id', 'code', 'name_en', 'name_am'])->keyBy('id');

        return $versions->map(function (HierarchyVersion $version) use ($edges, $organizationId, $organizations) {
            $own = $edges->where('hierarchy_version_id', $version->id);

            return [
                'id' => $version->id, 'version_name' => $version->version_name, 'status' => $version->status instanceof \BackedEnum ? $version->status->value : $version->status,
                'effective_from' => $version->effective_from?->toDateString(), 'effective_to' => $version->effective_to?->toDateString(),
                'parents' => $own->where('child_organization_id', $organizationId)->map(fn ($e) => [...($organizations->get($e->parent_organization_id)?->only(['id', 'code', 'name_en', 'name_am']) ?? ['id' => $e->parent_organization_id, 'code' => '?', 'name_en' => '?', 'name_am' => null]), 'relationship_type' => $e->relationship_type instanceof \BackedEnum ? $e->relationship_type->value : $e->relationship_type])->values()->all(),
                'children' => $own->where('parent_organization_id', $organizationId)->map(fn ($e) => [...($organizations->get($e->child_organization_id)?->only(['id', 'code', 'name_en', 'name_am']) ?? ['id' => $e->child_organization_id, 'code' => '?', 'name_en' => '?', 'name_am' => null]), 'relationship_type' => $e->relationship_type instanceof \BackedEnum ? $e->relationship_type->value : $e->relationship_type])->values()->all(),
            ];
        })->values()->all();
    }
}

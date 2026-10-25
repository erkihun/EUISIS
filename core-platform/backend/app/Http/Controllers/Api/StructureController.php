<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\SaveOrganization;
use App\Actions\SaveOrganizationUnit;
use App\Actions\SaveStructureType;
use App\Http\Controllers\Controller;
use App\Models\HierarchyVersion;
use App\Models\Organization;
use App\Models\OrganizationEdge;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\OrganizationUnitType;
use App\Services\OrganizationScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The organization structure: organization types, unit types, organizations,
 * their units and the published hierarchy. Writes go through the Core actions,
 * which check scope; lookups feed the forms with what the reader may choose.
 */
class StructureController extends Controller
{
    public function __construct(private readonly OrganizationScope $scope) {}

    /** Everything a structure form chooses from, inside the reader's scope. */
    public function lookups(Request $request): JsonResponse
    {
        $organizations = $this->scope->apply(Organization::query(), $request->user(), 'id')
            ->orderBy('name_en')->get(['id', 'code', 'name_en', 'name_am', 'status']);

        return response()->json([
            'organization_types' => OrganizationType::query()->orderBy('sort_order')->orderBy('name_en')->get(['id', 'code', 'name_en', 'name_am', 'is_active']),
            'unit_types' => OrganizationUnitType::query()->orderBy('sort_order')->orderBy('name_en')->get(['id', 'code', 'name_en', 'name_am', 'is_active']),
            'organizations' => $organizations,
            'units' => $request->filled('organization_id')
                ? $this->scope->apply(OrganizationUnit::query(), $request->user())->where('organization_id', $request->string('organization_id'))
                    ->orderBy('name_en')->get(['id', 'organization_id', 'parent_unit_id', 'code', 'name_en', 'name_am', 'status'])
                : [],
            'organization_statuses' => SaveOrganization::STATUSES,
            'unit_statuses' => SaveOrganizationUnit::STATUSES,
        ]);
    }

    /** Organization types or unit types, all of them (short lists). */
    public function types(string $kind): JsonResponse
    {
        $model = $this->typeModel($kind);

        return response()->json(['data' => $model::query()->orderBy('sort_order')->orderBy('name_en')
            ->get(['id', 'code', 'name_en', 'name_am', 'description_en', 'is_active', 'sort_order'])]);
    }

    public function saveType(Request $request, SaveStructureType $save, string $kind, ?string $id = null): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64'],
            'name_en' => ['required', 'string', 'max:255'],
            'name_am' => ['nullable', 'string', 'max:255'],
            'description_en' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['boolean'],
            'sort_order' => ['integer', 'min:0', 'max:9999'],
        ]);

        return response()->json(['data' => $save->execute($this->typeModel($kind), $id, $data, $request->user())], $id === null ? 201 : 200);
    }

    public function storeOrganization(Request $request, SaveOrganization $save): JsonResponse
    {
        return response()->json(['data' => $save->execute(null, $this->organizationData($request, true), $request->user())], 201);
    }

    public function updateOrganization(Request $request, Organization $organization, SaveOrganization $save): JsonResponse
    {
        return response()->json(['data' => $save->execute($organization, $this->organizationData($request, false), $request->user())]);
    }

    public function storeUnit(Request $request, SaveOrganizationUnit $save): JsonResponse
    {
        return response()->json(['data' => $save->execute(null, $this->unitData($request, true), $request->user())], 201);
    }

    public function updateUnit(Request $request, OrganizationUnit $unit, SaveOrganizationUnit $save): JsonResponse
    {
        return response()->json(['data' => $save->execute($unit, $this->unitData($request, false), $request->user())]);
    }

    /** The published hierarchy as a tree of organizations, cut to the reader's scope. */
    public function hierarchy(Request $request): JsonResponse
    {
        $version = HierarchyVersion::current();
        $ids = $this->scope->organizationIds($request->user());
        $organizations = Organization::query()->with('type:id,name_en,name_am')
            ->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))
            ->get(['id', 'code', 'name_en', 'name_am', 'status', 'organization_type_id'])->keyBy('id');
        $edges = $version === null ? collect() : OrganizationEdge::query()
            ->where('hierarchy_version_id', $version->id)->whereIn('relationship_type', OrganizationEdge::TREE_TYPES)
            ->get(['parent_organization_id', 'child_organization_id']);

        $parentOf = [];
        foreach ($edges as $edge) {
            if ($organizations->has($edge->child_organization_id) && $organizations->has($edge->parent_organization_id)) {
                $parentOf[(string) $edge->child_organization_id] = (string) $edge->parent_organization_id;
            }
        }
        $nodes = $organizations->map(fn (Organization $organization): array => [
            'id' => $organization->id,
            'code' => $organization->code,
            'name_en' => $organization->name_en,
            'name_am' => $organization->name_am,
            'status' => $organization->status,
            'type' => $organization->type?->name_en,
            'parent_id' => $parentOf[(string) $organization->id] ?? null,
        ])->values();

        return response()->json(['version' => $version?->only(['id', 'version_name', 'status', 'effective_from']), 'nodes' => $nodes]);
    }

    /** @return array<string, mixed> */
    private function organizationData(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'organization_type_id' => [$required, 'uuid', Rule::exists('organization_types', 'id')],
            'code' => ['nullable', 'string', 'max:64'],
            'name_en' => [$required, 'string', 'max:255'],
            'name_am' => ['nullable', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(SaveOrganization::STATUSES)],
            'effective_from' => ['nullable', 'date'],
            'parent_organization_id' => ['nullable', 'uuid', Rule::exists('organizations', 'id')],
        ]);
    }

    /** @return array<string, mixed> */
    private function unitData(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'organization_id' => [$required, 'uuid', Rule::exists('organizations', 'id')],
            'organization_unit_type_id' => [$required, 'uuid', Rule::exists('organization_unit_types', 'id')],
            'parent_unit_id' => ['nullable', 'uuid'],
            'code' => ['nullable', 'string', 'max:64'],
            'name_en' => [$required, 'string', 'max:255'],
            'name_am' => ['nullable', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(SaveOrganizationUnit::STATUSES)],
        ]);
    }

    /** @return class-string<OrganizationType|OrganizationUnitType> */
    private function typeModel(string $kind): string
    {
        return $kind === 'organization-types' ? OrganizationType::class : OrganizationUnitType::class;
    }
}

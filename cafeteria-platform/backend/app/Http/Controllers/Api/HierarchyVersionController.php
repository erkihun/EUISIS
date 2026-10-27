<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\ManageHierarchyVersion;
use App\Http\Controllers\Controller;
use App\Models\HierarchyVersion;
use App\Models\OrganizationEdge;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Hierarchy versions for city-wide administrators: every version (history
 * included), one version's tree edges with its impact against the structure
 * it was copied from, and the governed steps of ManageHierarchyVersion.
 */
class HierarchyVersionController extends Controller
{
    private const COLUMNS = ['id', 'version_name', 'status', 'effective_from', 'effective_to', 'legal_basis', 'source_document', 'notes',
        'base_version_id', 'created_by', 'reviewed_by', 'reviewed_at', 'approved_by', 'approval_date', 'created_at'];

    public function index(): JsonResponse
    {
        return response()->json(['data' => HierarchyVersion::query()->orderByDesc('created_at')->orderByDesc('id')->limit(200)->get(self::COLUMNS)]);
    }

    public function show(HierarchyVersion $hierarchyVersion, ManageHierarchyVersion $versions): JsonResponse
    {
        return response()->json([
            'data' => $hierarchyVersion->only(self::COLUMNS),
            'edges' => OrganizationEdge::query()->where('hierarchy_version_id', $hierarchyVersion->id)
                ->whereIn('relationship_type', OrganizationEdge::TREE_TYPES)
                ->get(['parent_organization_id', 'child_organization_id', 'relationship_type']),
            'impact' => $versions->impact($hierarchyVersion),
        ]);
    }

    public function store(Request $request, ManageHierarchyVersion $versions): JsonResponse
    {
        $data = $request->validate([
            'version_name' => ['required', 'string', 'max:255', Rule::unique('hierarchy_versions', 'version_name')],
            'effective_from' => ['required', 'date', 'after_or_equal:today'],
            'legal_basis' => ['nullable', 'string', 'max:2000'],
            'source_document' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json(['data' => $versions->create($data, $request->user())->only(self::COLUMNS)], 201);
    }

    public function changeParent(Request $request, HierarchyVersion $hierarchyVersion, ManageHierarchyVersion $versions): JsonResponse
    {
        $data = $request->validate([
            'child_organization_id' => ['required', 'uuid'],
            'parent_organization_id' => ['nullable', 'uuid', 'different:child_organization_id'],
        ]);

        return response()->json(['data' => $versions->changeParent($hierarchyVersion, $data['child_organization_id'], $data['parent_organization_id'] ?? null, $request->user())->only(self::COLUMNS)]);
    }

    public function transition(Request $request, HierarchyVersion $hierarchyVersion, string $transition, ManageHierarchyVersion $versions): JsonResponse
    {
        return response()->json(['data' => $versions->transition($hierarchyVersion, $transition, $request->user())->only(self::COLUMNS)]);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Organizations\ArchiveHierarchyVersionAction;
use App\Actions\Organizations\CreateHierarchyVersionAction;
use App\Actions\Organizations\PublishHierarchyVersionAction;
use App\Actions\Organizations\UpdateHierarchyVersionAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\ArchiveHierarchyVersionRequest;
use App\Http\Requests\PublishHierarchyVersionRequest;
use App\Http\Requests\StoreHierarchyVersionRequest;
use App\Http\Requests\UpdateHierarchyVersionRequest;
use App\Http\Resources\HierarchyTreeNodeResource;
use App\Http\Resources\HierarchyVersionResource;
use App\Http\Resources\OrganizationEdgeResource;
use App\Models\HierarchyVersion;
use App\Models\OrganizationEdge;
use App\Services\Hierarchy\HierarchyTreeService;
use App\Services\Organizations\OrganizationTreeService;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HierarchyVersionController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', HierarchyVersion::class);

        $filters = [
            'search' => $request->string('search')->toString() ?: null,
            'status' => $request->string('status')->toString() ?: null,
            'effective_from' => $request->string('effective_from')->toString() ?: null,
        ];

        $versions = HierarchyVersion::query()
            ->with('approver:id,name')
            ->withCount('edges')
            ->when($filters['search'], function ($query, string $search): void {
                $query->where(function ($innerQuery) use ($search): void {
                    $innerQuery
                        ->where('version_name', ci_like_operator(), "%{$search}%")
                        ->orWhere('source_document', ci_like_operator(), "%{$search}%")
                        ->orWhere('notes', ci_like_operator(), "%{$search}%");
                });
            })
            ->when($filters['status'], fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['effective_from'], fn ($query, string $effectiveFrom) => $query->whereDate('effective_from', '>=', $effectiveFrom))
            ->latest('created_at')
            ->paginate(12)
            ->withQueryString();

        return Inertia::render('HierarchyVersions/Index', [
            'versions' => HierarchyVersionResource::collection($versions),
            'filters' => $filters,
            'can' => [
                'create' => $request->user()?->can('hierarchy-versions.create') ?? false,
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', HierarchyVersion::class);

        return Inertia::render('HierarchyVersions/Create', [
            'can' => [
                'create' => $request->user()?->can('hierarchy-versions.create') ?? false,
            ],
        ]);
    }

    public function store(
        StoreHierarchyVersionRequest $request,
        CreateHierarchyVersionAction $createHierarchyVersionAction,
    ): RedirectResponse {
        $version = $createHierarchyVersionAction->execute($request->validated(), $request->user());

        return to_route('hierarchy-versions.show', $version)
            ->with('flash', ['message' => __('hierarchy-versions.created_successfully'), 'type' => 'success']);
    }

    public function show(HierarchyVersion $hierarchyVersion, Request $request, OrganizationScopeService $organizationScopeService, HierarchyTreeService $hierarchyTreeService): Response
    {
        $this->authorize('view', $hierarchyVersion);

        $hierarchyVersion->load('approver:id,name')->loadCount('edges');
        $tree = $hierarchyTreeService->buildFullTree($hierarchyVersion, $request->user());

        return Inertia::render('HierarchyVersions/Show', [
            'version' => HierarchyVersionResource::make($hierarchyVersion)->resolve($request),
            'edges' => OrganizationEdgeResource::collection(
                $hierarchyVersion->edges()
                    ->with([
                        'parentOrganization:id,organization_type_id,name_en,name_am,code,status,logo_path',
                        'parentOrganization.type:id,code,name_en,name_am',
                        'childOrganization:id,organization_type_id,name_en,name_am,code,status,logo_path',
                        'childOrganization.type:id,code,name_en,name_am',
                        'hierarchyVersion:id,status',
                    ])
                    ->orderBy('parent_organization_id')
                    ->orderBy('child_organization_id')
                    ->get()
            )->resolve($request),
            'tree' => HierarchyTreeNodeResource::collection(collect($tree))->resolve($request),
            'summary' => $organizationScopeService->summarizeVersionTree($tree),
            'can' => [
                'edit' => $this->canUpdate($request, $hierarchyVersion),
                'archive' => $this->canArchive($request, $hierarchyVersion),
                'publish' => $this->canPublish($request, $hierarchyVersion),
                'manageTree' => $this->canManageTree($request, $hierarchyVersion),
                'createEdge' => $this->canCreateEdge($request, $hierarchyVersion),
            ],
        ]);
    }

    public function edit(HierarchyVersion $hierarchyVersion, Request $request): Response
    {
        $this->authorize('view', $hierarchyVersion);

        return Inertia::render('HierarchyVersions/Edit', [
            'version' => HierarchyVersionResource::make($hierarchyVersion->load('approver:id,name')->loadCount('edges'))->resolve($request),
            'can' => [
                'update' => $this->canUpdate($request, $hierarchyVersion),
            ],
        ]);
    }

    public function update(
        UpdateHierarchyVersionRequest $request,
        HierarchyVersion $hierarchyVersion,
        UpdateHierarchyVersionAction $updateHierarchyVersionAction,
    ): RedirectResponse {
        $updateHierarchyVersionAction->execute($request->validated(), $hierarchyVersion, $request->user());

        return to_route('hierarchy-versions.show', $hierarchyVersion)
            ->with('flash', ['message' => __('hierarchy-versions.updated_successfully'), 'type' => 'success']);
    }

    public function publish(
        PublishHierarchyVersionRequest $request,
        HierarchyVersion $hierarchyVersion,
        PublishHierarchyVersionAction $publishHierarchyVersionAction,
        OrganizationScopeService $organizationScopeService,
    ): RedirectResponse {
        $publishHierarchyVersionAction->execute($hierarchyVersion, $request->user());

        $organizationScopeService->clearCache();

        return to_route('hierarchy-versions.show', $hierarchyVersion)
            ->with('flash', ['message' => __('hierarchy-versions.published_successfully'), 'type' => 'success']);
    }

    public function archive(
        ArchiveHierarchyVersionRequest $request,
        HierarchyVersion $hierarchyVersion,
        ArchiveHierarchyVersionAction $archiveHierarchyVersionAction,
    ): RedirectResponse {
        $archiveHierarchyVersionAction->execute($hierarchyVersion, $request->user());

        return to_route('hierarchy-versions.show', $hierarchyVersion)
            ->with('flash', ['message' => __('hierarchy-versions.archived_successfully'), 'type' => 'success']);
    }

    public function tree(
        HierarchyVersion $hierarchyVersion,
        Request $request,
        OrganizationScopeService $organizationScopeService,
        HierarchyTreeService $hierarchyTreeService,
    ): Response {
        $this->authorize('view', $hierarchyVersion);

        $filters = [
            'include_units' => filter_var($request->query('include_units', 'true'), FILTER_VALIDATE_BOOLEAN),
            'include_inactive' => filter_var($request->query('include_inactive', 'false'), FILTER_VALIDATE_BOOLEAN),
        ];

        $tree = $hierarchyTreeService->buildFullTree($hierarchyVersion, $request->user(), $filters);

        return Inertia::render('HierarchyVersions/Tree', [
            'version' => HierarchyVersionResource::make($hierarchyVersion->load('approver:id,name')->loadCount('edges'))->resolve($request),
            'tree' => HierarchyTreeNodeResource::collection(collect($tree))->resolve($request),
            'edges' => OrganizationEdgeResource::collection(
                $hierarchyVersion->edges()
                    ->with([
                        'parentOrganization:id,organization_type_id,name_en,name_am,code,status,logo_path',
                        'parentOrganization.type:id,code,name_en,name_am',
                        'childOrganization:id,organization_type_id,name_en,name_am,code,status,logo_path',
                        'childOrganization.type:id,code,name_en,name_am',
                        'hierarchyVersion:id,status',
                    ])
                    ->orderBy('parent_organization_id')
                    ->orderBy('child_organization_id')
                    ->get()
            )->resolve($request),
            'summary' => $organizationScopeService->summarizeVersionTree($tree),
            'filters' => $filters,
            'can' => [
                'manageTree' => $this->canManageTree($request, $hierarchyVersion),
                'createEdge' => $this->canCreateEdge($request, $hierarchyVersion),
            ],
        ]);
    }

    public function editTree(
        HierarchyVersion $hierarchyVersion,
        Request $request,
        OrganizationScopeService $organizationScopeService,
        OrganizationTreeService $organizationTreeService,
        HierarchyTreeService $hierarchyTreeService,
    ): Response {
        $this->authorize('view', $hierarchyVersion);
        $tree = $hierarchyTreeService->buildFullTree($hierarchyVersion, $request->user());

        return Inertia::render('HierarchyVersions/EditTree', [
            'version' => HierarchyVersionResource::make($hierarchyVersion->load('approver:id,name')->loadCount('edges'))->resolve($request),
            'tree' => HierarchyTreeNodeResource::collection(collect($tree))->resolve($request),
            'edges' => OrganizationEdgeResource::collection(
                $hierarchyVersion->edges()
                    ->with([
                        'parentOrganization:id,organization_type_id,name_en,name_am,code,status,logo_path',
                        'parentOrganization.type:id,code,name_en,name_am',
                        'childOrganization:id,organization_type_id,name_en,name_am,code,status,logo_path',
                        'childOrganization.type:id,code,name_en,name_am',
                        'hierarchyVersion:id,status',
                    ])
                    ->orderBy('parent_organization_id')
                    ->orderBy('child_organization_id')
                    ->get()
            )->resolve($request),
            'organizationOptions' => $organizationTreeService->editableOrganizationOptions($request->user()),
            'relationshipTypes' => $organizationTreeService->relationshipTypeOptions(),
            'summary' => $organizationScopeService->summarizeVersionTree($tree),
            'can' => [
                'manageTree' => $this->canManageTree($request, $hierarchyVersion),
                'createEdge' => $this->canCreateEdge($request, $hierarchyVersion),
            ],
        ]);
    }

    public function organizationOptions(
        HierarchyVersion $hierarchyVersion,
        Request $request,
        OrganizationTreeService $organizationTreeService,
    ): JsonResponse {
        $this->authorize('view', $hierarchyVersion);

        abort_unless(
            $this->canManageTree($request, $hierarchyVersion)
            || $this->canCreateEdge($request, $hierarchyVersion),
            403,
        );

        return response()->json([
            'options' => $organizationTreeService
                ->editableOrganizationOptions(
                    $request->user(),
                    $request->string('q')->toString() ?: null,
                    $request->string('selected_id')->toString() ?: null,
                )
                ->values(),
        ]);
    }

    private function canUpdate(Request $request, HierarchyVersion $hierarchyVersion): bool
    {
        return ($request->user()?->can('hierarchy-versions.update') ?? false)
            && $hierarchyVersion->status->value === 'draft';
    }

    private function canArchive(Request $request, HierarchyVersion $hierarchyVersion): bool
    {
        return ($request->user()?->can('hierarchy-versions.archive') ?? false)
            && $hierarchyVersion->status->value === 'draft';
    }

    private function canPublish(Request $request, HierarchyVersion $hierarchyVersion): bool
    {
        return ($request->user()?->can('hierarchy-versions.publish') ?? false)
            && $hierarchyVersion->status->value === 'draft';
    }

    private function canManageTree(Request $request, HierarchyVersion $hierarchyVersion): bool
    {
        return ($request->user()?->can('hierarchy-versions.manageTree') ?? false)
            && $hierarchyVersion->status->value === 'draft';
    }

    private function canCreateEdge(Request $request, HierarchyVersion $hierarchyVersion): bool
    {
        return ($request->user()?->can('create', [OrganizationEdge::class, $hierarchyVersion]) ?? false)
            && $hierarchyVersion->status->value === 'draft';
    }
}

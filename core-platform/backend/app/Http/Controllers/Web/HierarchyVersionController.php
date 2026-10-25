<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\ManageHierarchyVersion;
use App\Http\Controllers\Controller;
use App\Models\HierarchyVersion;
use App\Models\Organization;
use App\Models\OrganizationEdge;
use App\Models\User;
use App\Services\HierarchyTree;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The governed hierarchy on the EUISIS screens (HierarchyVersions/Create,
 * Edit and EditTree, the relation modal and its organization search) over
 * ManageHierarchyVersion (ADR 0008): a city-wide administrator prepares a
 * draft, another approves it, and it is published on its effective date.
 * A relation is one organization's structural parent; changing or removing
 * it is ManageHierarchyVersion::changeParent, so the tree stays one tree.
 */
class HierarchyVersionController extends Controller
{
    private const DATE_MESSAGES = [
        'effective_to.prohibited' => 'The end date is set when the next version is published.',
        'effective_from.prohibited' => 'A relation takes the effective date of its version.',
    ];

    public function __construct(private readonly ManageHierarchyVersion $versions, private readonly HierarchyTree $tree) {}

    public function create(Request $request): Response
    {
        $this->governor($request);

        return Inertia::render('HierarchyVersions/Create');
    }

    public function store(Request $request): RedirectResponse
    {
        $version = $this->versions->create($this->details($request), $this->governor($request));

        return to_route('hierarchy-versions.show', $version)
            ->with('flash', ['message' => __('hierarchy-versions.created_successfully'), 'type' => 'success']);
    }

    public function edit(Request $request, HierarchyVersion $hierarchyVersion): Response|RedirectResponse
    {
        $this->governor($request);
        if ($hierarchyVersion->status !== 'draft') {
            return $this->onlyDrafts($hierarchyVersion);
        }

        return Inertia::render('HierarchyVersions/Edit', ['version' => $this->tree->version($hierarchyVersion), 'can' => ['update' => true]]);
    }

    public function update(Request $request, HierarchyVersion $hierarchyVersion): RedirectResponse
    {
        $this->versions->updateDraft($hierarchyVersion, $this->details($request, $hierarchyVersion), $this->governor($request));

        return to_route('hierarchy-versions.show', $hierarchyVersion)
            ->with('flash', ['message' => __('hierarchy-versions.updated_successfully'), 'type' => 'success']);
    }

    public function editTree(Request $request, HierarchyVersion $hierarchyVersion): Response|RedirectResponse
    {
        $user = $this->governor($request);
        if ($hierarchyVersion->status !== 'draft') {
            return $this->onlyDrafts($hierarchyVersion);
        }
        $tree = $this->tree->build($hierarchyVersion, $user, false, false, true);

        return Inertia::render('HierarchyVersions/EditTree', [
            'version' => $this->tree->version($hierarchyVersion),
            'tree' => $tree,
            'edges' => $this->tree->edges($hierarchyVersion, $user, true),
            'organizationOptions' => $this->options(null, null),
            'relationshipTypes' => [OrganizationEdge::REPORTS_TO],
            'summary' => $this->tree->summary($tree),
            'can' => ['manageTree' => true, 'createEdge' => true],
        ]);
    }

    public function organizationOptions(Request $request, HierarchyVersion $hierarchyVersion): JsonResponse
    {
        $this->governor($request);
        $data = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'selected_id' => ['nullable', 'uuid']]);

        return response()->json(['options' => $this->options($data['q'] ?? null, $data['selected_id'] ?? null)]);
    }

    public function storeEdge(Request $request, HierarchyVersion $hierarchyVersion): RedirectResponse
    {
        $user = $this->governor($request);
        $data = $request->validate($this->edgeRules(), self::DATE_MESSAGES);
        $hasParent = OrganizationEdge::query()->where('hierarchy_version_id', $hierarchyVersion->id)
            ->whereIn('relationship_type', OrganizationEdge::TREE_TYPES)->where('child_organization_id', $data['child_organization_id'])->exists();
        if ($hasParent) {
            throw ValidationException::withMessages(['child_organization_id' => 'This organization already has a parent in this version; edit that relation to move it.']);
        }
        $this->versions->changeParent($hierarchyVersion, $data['child_organization_id'], $data['parent_organization_id'], $user);

        return back()->with('flash', ['message' => __('hierarchy-versions.edge_created_successfully'), 'type' => 'success']);
    }

    public function updateEdge(Request $request, HierarchyVersion $hierarchyVersion, OrganizationEdge $organizationEdge): RedirectResponse
    {
        $user = $this->governor($request);
        $edge = $this->treeEdge($hierarchyVersion, $organizationEdge);
        $data = $request->validate(array_merge($this->edgeRules(), ['child_organization_id' => ['required', 'uuid', Rule::in([$edge->child_organization_id])]]),
            self::DATE_MESSAGES + ['child_organization_id.in' => 'To move another organization, add a relation for it.']);
        $this->versions->changeParent($hierarchyVersion, $edge->child_organization_id, $data['parent_organization_id'], $user);

        return back()->with('flash', ['message' => __('hierarchy-versions.edge_updated_successfully'), 'type' => 'success']);
    }

    public function destroyEdge(Request $request, HierarchyVersion $hierarchyVersion, OrganizationEdge $organizationEdge): RedirectResponse
    {
        $user = $this->governor($request);
        $edge = $this->treeEdge($hierarchyVersion, $organizationEdge);
        $this->versions->changeParent($hierarchyVersion, $edge->child_organization_id, null, $user);

        return back()->with('flash', ['message' => __('hierarchy-versions.edge_removed_successfully'), 'type' => 'success']);
    }

    /** Submit for review, approve, send back to draft, or publish. */
    public function transition(Request $request, HierarchyVersion $hierarchyVersion, string $transition): RedirectResponse
    {
        $this->versions->transition($hierarchyVersion, $transition, $this->governor($request));
        $message = match ($transition) {
            'review' => 'hierarchy-versions.submitted_for_review',
            'approve' => 'hierarchy-versions.approved_successfully',
            'reject' => 'hierarchy-versions.returned_to_draft',
            default => 'hierarchy-versions.published_successfully',
        };

        return to_route('hierarchy-versions.show', $hierarchyVersion)->with('flash', ['message' => __($message), 'type' => 'success']);
    }

    private function governor(Request $request): User
    {
        $user = $request->user();
        abort_unless(ManageHierarchyVersion::governs($user), 403, 'The governed hierarchy is for city-wide Core administrators.');

        return $user;
    }

    /** @return array<string, mixed> */
    private function details(Request $request, ?HierarchyVersion $version = null): array
    {
        $data = $request->validate([
            'version_name' => ['required', 'string', 'max:255', Rule::unique('hierarchy_versions', 'version_name')->ignore($version?->id)],
            'effective_from' => ['required', 'date', 'after_or_equal:today'],
            'effective_to' => ['prohibited'],
            'legal_basis' => ['nullable', 'string', 'max:2000'],
            'source_document' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], self::DATE_MESSAGES);
        unset($data['effective_to']);

        return $data;
    }

    /** @return array<string, list<mixed>> */
    private function edgeRules(): array
    {
        return [
            'parent_organization_id' => ['required', 'uuid', 'different:child_organization_id'],
            'child_organization_id' => ['required', 'uuid'],
            'relationship_type' => ['required', Rule::in([OrganizationEdge::REPORTS_TO])],
            'effective_from' => ['prohibited'],
            'effective_to' => ['prohibited'],
        ];
    }

    private function treeEdge(HierarchyVersion $version, OrganizationEdge $edge): OrganizationEdge
    {
        abort_unless((string) $edge->hierarchy_version_id === (string) $version->id && in_array($edge->relationship_type, OrganizationEdge::TREE_TYPES, true), 404);

        return $edge;
    }

    private function onlyDrafts(HierarchyVersion $version): RedirectResponse
    {
        return to_route('hierarchy-versions.show', $version)
            ->with('flash', ['message' => __('hierarchy-versions.only_draft_can_be_edited'), 'type' => 'warning']);
    }

    /** @return list<array<string, mixed>> */
    private function options(?string $search, ?string $selectedId): array
    {
        $organizations = Organization::query()->with('type:id,code,name_en,name_am')
            ->when($search, fn ($q, string $term) => $q->where(fn ($inner) => $inner->where('name_en', 'like', "%{$term}%")
                ->orWhere('name_am', 'like', "%{$term}%")->orWhere('code', 'like', "%{$term}%")))
            ->orderBy('name_en')->limit(25)->get(['id', 'organization_type_id', 'code', 'name_en', 'name_am', 'status']);
        if ($selectedId !== null && ! $organizations->contains('id', $selectedId)) {
            $organizations = Organization::query()->with('type:id,code,name_en,name_am')->whereKey($selectedId)
                ->get(['id', 'organization_type_id', 'code', 'name_en', 'name_am', 'status'])->concat($organizations);
        }

        return $organizations->map(fn (Organization $organization): array => $organization->only(['id', 'code', 'name_en', 'name_am', 'status'])
            + ['type' => $organization->type?->only(['code', 'name_en', 'name_am'])])->values()->all();
    }
}

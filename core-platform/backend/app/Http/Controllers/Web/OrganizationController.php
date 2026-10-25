<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\DeleteOrganization;
use App\Actions\SaveOrganization;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\HierarchyVersion;
use App\Models\IdCard;
use App\Models\Organization;
use App\Models\OrganizationEdge;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Models\User;
use App\Services\CodeGenerator;
use App\Services\OrganizationScope;
use App\Services\OrganizationUsage;
use App\Services\StructureTree;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** EUISIS Organizations/* pages over the Core tables; writes go through SaveOrganization. */
class OrganizationController extends Controller
{
    public function __construct(private readonly OrganizationScope $scope, private readonly OrganizationUsage $usage) {}

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $version = HierarchyVersion::current();
        $filters = [
            'search' => $request->string('search')->trim()->toString(),
            'type' => $request->string('type')->toString(),
            'status' => $request->string('status')->toString(),
            'category' => $request->string('category')->toString(),
        ];

        $query = Organization::query()->where('status', '!=', 'archived')->with('type:id,code,name_en,name_am,category')
            ->when($filters['search'] !== '', fn ($q) => $q->where(fn ($inner) => $inner->where('code', 'like', '%'.$filters['search'].'%')
                ->orWhere('name_en', 'like', '%'.$filters['search'].'%')->orWhere('name_am', 'like', '%'.$filters['search'].'%')))
            ->when($filters['type'] !== '', fn ($q) => $q->whereHas('type', fn ($t) => $t->where('code', $filters['type'])))
            ->when($filters['category'] !== '', fn ($q) => $q->whereHas('type', fn ($t) => $t->where('category', $filters['category'])))
            ->when($filters['status'] !== '', fn ($q) => $q->where('status', $filters['status']))
            ->orderBy('name_en');
        $paginator = $this->scope->apply($query, $user, 'id')
            ->paginate(20, ['id', 'code', 'name_en', 'name_am', 'status', 'organization_type_id', 'created_at'])->withQueryString();

        $parentIds = $version === null ? collect() : OrganizationEdge::query()->where('hierarchy_version_id', $version->id)
            ->whereIn('relationship_type', OrganizationEdge::TREE_TYPES)
            ->whereIn('child_organization_id', $paginator->getCollection()->pluck('id'))->pluck('parent_organization_id', 'child_organization_id');
        $parents = Organization::query()->whereIn('id', $parentIds->values()->unique())->get(['id', 'code', 'name_en', 'name_am'])->keyBy('id');

        $paginator->setCollection($paginator->getCollection()->map(fn (Organization $organization): array => [
            'id' => $organization->id,
            'code' => $organization->code,
            'name_en' => $organization->name_en,
            'name_am' => $organization->name_am,
            'status' => $organization->status,
            'type' => $organization->type,
            'created_at' => $organization->created_at?->toIso8601String(),
            'parent' => $parents->get($parentIds->get($organization->id))?->only(['id', 'code', 'name_en', 'name_am']),
            'can' => $this->rowCan($user, $organization),
            'deletion_blockers' => $this->blockers($organization),
        ])->values());

        $scoped = fn () => $this->scope->apply(Organization::query()->where('status', '!=', 'archived'), $user, 'id');

        return Inertia::render('Organizations/Index', [
            'organizations' => $paginator,
            'filters' => $filters,
            'filterOptions' => [
                'types' => OrganizationType::query()->orderBy('name_en')->get(['code', 'name_en', 'name_am']),
                'statuses' => array_values(array_diff(SaveOrganization::STATUSES, ['archived'])),
                'categories' => OrganizationType::query()->whereNotNull('category')->distinct()->orderBy('category')->pluck('category'),
            ],
            'stats' => [
                'total' => $scoped()->count(),
                'active' => $scoped()->where('status', 'active')->count(),
                'inactive' => $scoped()->where('status', 'inactive')->count(),
                'types' => $scoped()->distinct()->count('organization_type_id'),
            ],
            'publishedVersion' => $version?->only(['id', 'version_name', 'approval_date']),
            'hierarchyVersions' => HierarchyVersion::query()->orderByDesc('created_at')->get(['id', 'version_name', 'status', 'approval_date']),
            'can' => ['create' => $user->can('organizations.create'), 'manageHierarchy' => false],
        ]);
    }

    public function show(Request $request, Organization $organization, StructureTree $tree): Response
    {
        Gate::authorize('view', $organization);
        /** @var User $user */
        $user = $request->user();
        abort_unless($this->scope->allows($user, (string) $organization->id), 403);
        $version = HierarchyVersion::current();
        $organization->load('type');
        $parentId = $version === null ? null : OrganizationEdge::query()->where('hierarchy_version_id', $version->id)
            ->whereIn('relationship_type', OrganizationEdge::TREE_TYPES)->where('child_organization_id', $organization->id)->value('parent_organization_id');
        $descendants = $tree->descendants((string) $organization->id, $version?->id);

        return Inertia::render('Organizations/Show', [
            'organization' => $this->payload($organization) + ['name_histories' => []],
            'parentOrganizationId' => $parentId,
            'parentOrganization' => $parentId === null ? null : Organization::query()->find($parentId, ['id', 'code', 'name_en', 'name_am'])?->only(['id', 'code', 'name_en', 'name_am']),
            'currentAssignmentsCount' => $organization->assignments()->where('is_current', true)->count(),
            'structureSummary' => [
                'units' => $organization->units()->count(),
                'positions' => $organization->positions()->count(),
                'descendants' => count($descendants),
            ],
            'structureTree' => $tree->forOrganization($organization),
            'statistics' => $this->statistics($organization, count(array_filter($descendants, fn (array $row): bool => $row['depth'] === 1))),
            'descendants' => $descendants,
            'institutionOffices' => [],
            'reportingOffices' => [],
            'reportingUnits' => [],
            'can' => $this->rowCan($user, $organization) + ['feedbackQr' => false],
            'deletionBlockers' => $this->blockers($organization),
        ]);
    }

    public function organogram(Request $request, Organization $organization, StructureTree $tree): Response
    {
        abort_unless($this->scope->allows($request->user(), (string) $organization->id), 403);

        return Inertia::render('Organizations/Organogram', ['tree' => $tree->forOrganization($organization->load('type'))]);
    }

    public function create(Request $request): Response
    {
        $selected = $request->string('parent')->toString() ?: null;
        $options = $this->parentOptionList($request->user(), null, $selected, null);

        return Inertia::render('Organizations/Create', [
            'organizationTypes' => OrganizationType::query()->where('is_active', true)->orderBy('name_en')->get(['id', 'name_en', 'name_am', 'code']),
            // New parent edges go into the published hierarchy (ADR 0006).
            'hierarchyVersions' => collect([HierarchyVersion::current()])->filter()->map(fn (HierarchyVersion $version): array => $version->only(['id', 'version_name', 'status']))->values(),
            'parentOrganizationOptions' => $options['options'],
            'selectedParentOrganization' => $options['selected'],
            'requiresParentOrganization' => ! $request->user()->isCityWide(),
        ]);
    }

    public function parentOptions(Request $request): JsonResponse
    {
        return response()->json($this->parentOptionList(
            $request->user(),
            $request->string('q')->toString() ?: null,
            $request->string('selected_id')->toString() ?: null,
            $request->string('current_organization_id')->toString() ?: null,
        ));
    }

    public function store(Request $request, SaveOrganization $save): RedirectResponse
    {
        $data = $this->validated($request, true);
        $organization = $save->execute(null, $data, $request->user());
        $parentId = $data['parent_organization_id'] ?? null;

        return to_route('organizations.show', $parentId ?? $organization->id)
            ->with('flash', ['message' => __($parentId ? 'organizations.child_organization_created_successfully' : 'organizations.created_successfully'), 'type' => 'success']);
    }

    public function edit(Organization $organization): Response
    {
        return Inertia::render('Organizations/Edit', [
            'organization' => $this->payload($organization->load('type')),
            'organizationTypes' => OrganizationType::query()->where(fn ($q) => $q->where('is_active', true)->orWhere('id', $organization->organization_type_id))
                ->orderBy('name_en')->get(['id', 'name_en', 'name_am', 'code']),
        ]);
    }

    public function update(Request $request, Organization $organization, SaveOrganization $save): RedirectResponse
    {
        Gate::authorize('update', $organization);
        $save->execute($organization, $this->validated($request, false), $request->user());

        return to_route('organizations.show', $organization)->with('flash', ['message' => __('organizations.updated_successfully'), 'type' => 'success']);
    }

    public function archive(Request $request, Organization $organization, SaveOrganization $save): RedirectResponse
    {
        Gate::authorize('archive', $organization);
        $save->execute($organization, ['status' => 'archived'], $request->user());

        return to_route('organizations.index')->with('flash', ['message' => __('organizations.archived_successfully'), 'type' => 'success']);
    }

    /** Soft delete for an organization nothing refers to (DeleteOrganization); the EUISIS page asks no reason. */
    public function destroy(Request $request, Organization $organization, DeleteOrganization $delete): RedirectResponse
    {
        Gate::authorize('delete', $organization);
        $delete->execute($organization, $request->string('reason')->toString() ?: __('Deleted on the organization page.'), $request->user());

        return to_route('organizations.index')->with('flash', ['message' => __('organizations.deleted_successfully'), 'type' => 'success']);
    }

    public function deactivate(Request $request, Organization $organization, SaveOrganization $save): RedirectResponse
    {
        Gate::authorize('archive', $organization);
        $save->execute($organization, ['status' => 'inactive'], $request->user());

        return to_route('organizations.show', $organization)->with('flash', ['message' => __('organizations.deactivated_successfully'), 'type' => 'success']);
    }

    /** The code a new organization would get (the form's code field). */
    public function previewCode(Request $request, CodeGenerator $codes): JsonResponse
    {
        $entity = match ($request->string('entity_type')->toString()) {
            'organization' => 'organization',
            'organization_unit', 'unit' => 'unit',
            'position', 'job_position' => 'position',
            'employee' => 'employee',
            default => null,
        };
        $code = $entity === null ? null : $codes->preview($entity);

        return response()->json([
            'code' => $code,
            'rule' => $code === null ? null : ['id' => $entity, 'name' => __('Core numbering'), 'entity_type' => $entity, 'is_scoped' => false, 'scope_type' => null],
            'manual_override_allowed' => true,
            'requires_override_permission' => false,
            'error' => null,
        ]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'organization_type_id' => [$required, 'uuid', Rule::exists('organization_types', 'id')],
            'code' => ['nullable', 'string', 'max:64'],
            'name_en' => [$required, 'string', 'max:255'],
            'name_am' => ['nullable', 'string', 'max:255'],
            'legal_basis_ref' => ['nullable', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(SaveOrganization::STATUSES)],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'parent_organization_id' => ['nullable', 'uuid', Rule::exists('organizations', 'id')],
            'branding_primary_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'branding_secondary_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'public_contact_phone' => ['nullable', 'string', 'max:32'],
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(Organization $organization): array
    {
        return array_merge($organization->toArray(), [
            'logo_url' => null,
            'has_logo' => false,
            'merged_into' => null,
            'effective_from' => $organization->effective_from?->toDateString(),
            'effective_to' => $organization->effective_to?->toDateString(),
        ]);
    }

    /**
     * Why the organization cannot be deleted (OrganizationUsage), in the keys the EUISIS page names.
     *
     * @return list<string>
     */
    private function blockers(Organization $organization): array
    {
        $keys = [
            'hierarchy_history' => 'usedInPublishedHierarchy', 'hierarchy_drafts' => 'usedInPublishedHierarchy',
            'units' => 'hasOrganizationUnits', 'positions' => 'hasPositions', 'establishments' => 'hasPositions',
            'assignments' => 'hasEmployeeAssignments', 'scoped_accounts' => 'hasOtherReferences',
        ];

        return array_values(array_unique(array_map(fn (string $key): string => $keys[$key] ?? 'hasOtherReferences', array_keys($this->usage->deletionBlockers($organization)))));
    }

    /** @return array{update: bool, delete: bool, archive: bool, deactivate: bool, createChild: bool} */
    private function rowCan(User $user, Organization $organization): array
    {
        $inScope = $this->scope->allows($user, (string) $organization->id);

        return [
            'update' => $inScope && $user->can('organizations.update'),
            'delete' => $inScope && $user->can('organizations.delete'),
            'archive' => $inScope && $user->can('organizations.archive') && $organization->status !== 'archived',
            'deactivate' => $inScope && $user->can('organizations.update') && $organization->status === 'active',
            'createChild' => $inScope && $user->can('organizations.create'),
        ];
    }

    /** @return array<string, mixed> */
    private function statistics(Organization $organization, int $children): array
    {
        $count = fn ($rows, string $column): array => $rows->groupBy($column)->map->count()->all();
        $units = OrganizationUnit::query()->where('organization_id', $organization->id)->get(['status']);
        $positions = Position::query()->where('organization_id', $organization->id)->withCount('holders')->get(['id', 'is_active']);
        $employeeIds = EmployeeAssignment::query()->where('organization_id', $organization->id)->where('is_current', true)->pluck('employee_id');
        $employees = Employee::query()->whereIn('id', $employeeIds)->get(['status']);
        $cards = IdCard::query()->whereIn('employee_id', $employeeIds)->where('is_current', true)->get(['status']);
        $occupied = $positions->where('holders_count', '>', 0)->count();
        $byUnit = EmployeeAssignment::query()->where('organization_id', $organization->id)->where('is_current', true)->whereNotNull('organization_unit_id')
            ->selectRaw('organization_unit_id, count(*) as employees_count')->groupBy('organization_unit_id')->orderByDesc('employees_count')->limit(10)->get();
        $unitNames = OrganizationUnit::query()->whereIn('id', $byUnit->pluck('organization_unit_id'))->get(['id', 'code', 'name_en', 'name_am'])->keyBy('id');
        $cardStatuses = $count($cards, 'status');

        return [
            'units' => ['total' => $units->count()] + array_map(fn (string $s): int => $units->where('status', $s)->count(), ['active' => 'active', 'inactive' => 'inactive', 'draft' => 'draft', 'archived' => 'archived']),
            'positions' => [
                'total' => $positions->count(), 'active' => $positions->where('is_active', true)->count(), 'inactive' => $positions->where('is_active', false)->count(),
                'occupied' => $occupied, 'vacant' => max(0, $positions->count() - $occupied),
                'by_status' => ['active' => $positions->where('is_active', true)->count(), 'inactive' => $positions->where('is_active', false)->count()],
            ],
            'employees' => ['total' => $employees->count()] + array_map(fn (string $s): int => $employees->where('status', $s)->count(), ['active' => 'active', 'suspended' => 'suspended', 'retired' => 'retired', 'terminated' => 'terminated'])
                + ['by_status' => $count($employees, 'status')],
            'id_cards' => [
                'total' => $cards->count(), 'active' => $cards->where('status', IdCard::ACTIVE)->count(),
                'pending' => $cards->whereIn('status', [IdCard::PENDING_PRINT, IdCard::PRINTED, IdCard::ISSUED])->count(),
                'inactive' => $cards->whereNotIn('status', [IdCard::ACTIVE, IdCard::PENDING_PRINT, IdCard::PRINTED, IdCard::ISSUED])->count(),
                'by_status' => $cardStatuses,
            ],
            'child_organizations' => $children,
            'scoped_users' => User::query()->where('scope_organization_id', $organization->id)->count(),
            'employees_by_unit' => $byUnit->map(fn ($row): array => [
                'id' => (string) $row->organization_unit_id,
                'code' => $unitNames->get($row->organization_unit_id)?->code,
                'name_en' => $unitNames->get($row->organization_unit_id)?->name_en,
                'name_am' => $unitNames->get($row->organization_unit_id)?->name_am,
                'employees_count' => (int) $row->employees_count,
            ])->values()->all(),
        ];
    }

    /** @return array{options: list<array<string, mixed>>, selected: array<string, mixed>|null} */
    private function parentOptionList(User $user, ?string $search, ?string $selectedId, ?string $currentId): array
    {
        $query = Organization::query()->with('type:id,name_en,name_am,code')->where('status', 'active')
            ->when($currentId !== null, fn ($q) => $q->whereKeyNot($currentId))
            ->when($search !== null, fn ($q) => $q->where(fn ($inner) => $inner->where('code', 'like', '%'.$search.'%')
                ->orWhere('name_en', 'like', '%'.$search.'%')->orWhere('name_am', 'like', '%'.$search.'%')))
            ->orderBy('name_en');
        $this->scope->apply($query, $user, 'id');
        $organizations = (clone $query)->limit(25)->get(['id', 'organization_type_id', 'code', 'name_en', 'name_am', 'status']);
        if ($selectedId !== null && ! $organizations->contains('id', $selectedId)) {
            $organizations = $organizations->merge((clone $query)->whereKey($selectedId)->get(['id', 'organization_type_id', 'code', 'name_en', 'name_am', 'status']));
        }
        $options = $organizations->map(fn (Organization $organization): array => [
            'id' => $organization->id,
            'code' => $organization->code,
            'name_en' => $organization->name_en,
            'name_am' => $organization->name_am,
            'status' => $organization->status,
            'organization_type' => $organization->type?->only(['code', 'name_en', 'name_am']),
            'depth' => null,
            'parent_path' => null,
            'can_create_child' => $user->can('organizations.create'),
        ])->values()->all();

        return ['options' => $options, 'selected' => $selectedId === null ? null : collect($options)->firstWhere('id', $selectedId)];
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\SaveOrganizationUnit;
use App\Http\Controllers\Controller;
use App\Models\HierarchyVersion;
use App\Models\Organization;
use App\Models\OrganizationEdge;
use App\Models\OrganizationUnit;
use App\Models\OrganizationUnitType;
use App\Models\User;
use App\Services\AuditWriter;
use App\Services\OrganizationScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** EUISIS OrganizationUnits/* over the Core tables; writes go through SaveOrganizationUnit. */
class OrganizationUnitController extends Controller
{
    public function __construct(private readonly OrganizationScope $scope) {}

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $ids = $this->scope->organizationIds($user);
        $organizations = Organization::query()->with('type:id,name_en,name_am,code')->withCount('units')->orderBy('name_en')
            ->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))
            ->get(['id', 'organization_type_id', 'code', 'name_en', 'name_am', 'status', 'logo_path', 'effective_from']);
        $version = HierarchyVersion::current();
        $versionRow = fn (HierarchyVersion $v): array => ['id' => $v->id, 'name' => $v->version_name, 'status' => $v->status, 'is_draft' => $v->status === 'draft', 'effective_from' => $v->effective_from?->toDateString()];
        $node = fn (Organization $organization, int $depth, array $children): array => [
            'id' => $organization->id,
            'code' => $organization->code,
            'name_en' => $organization->name_en,
            'name_am' => $organization->name_am,
            'status' => $organization->status,
            'logo_url' => null,
            'has_logo' => false,
            'organization_units_count' => $organization->units_count,
            'type' => $organization->type?->only(['id', 'code', 'name_en', 'name_am']),
            'depth' => $depth,
            'children' => $children,
        ];
        $byId = $organizations->keyBy('id');

        if ($version !== null) {
            $childrenOf = [];
            $childIds = [];
            foreach (OrganizationEdge::query()->where('hierarchy_version_id', $version->id)->get(['parent_organization_id', 'child_organization_id']) as $edge) {
                if ($byId->has($edge->parent_organization_id) && $byId->has($edge->child_organization_id)) {
                    $childrenOf[$edge->parent_organization_id][] = $edge->child_organization_id;
                    $childIds[$edge->child_organization_id] = true;
                }
            }
            $build = function (string $id, int $depth, array $path) use (&$build, $byId, $childrenOf, $node): ?array {
                if (in_array($id, $path, true)) {
                    return null;
                }
                $children = array_values(array_filter(array_map(fn (string $child): ?array => $build($child, $depth + 1, [...$path, $id]), $childrenOf[$id] ?? [])));

                return $node($byId->get($id), $depth, $children);
            };
            $tree = $organizations->reject(fn (Organization $o): bool => isset($childIds[$o->id]))->map(fn (Organization $o): ?array => $build((string) $o->id, 0, []))->filter()->values()->all();
        } else {
            $tree = $organizations->map(fn (Organization $o): array => $node($o, 0, []))->values()->all();
        }

        $selected = null;
        $units = [];
        if ($request->filled('organization_id')) {
            $organizationId = (string) $request->get('organization_id');
            abort_unless($this->scope->allows($user, $organizationId), 403);
            $selected = Organization::query()->with('type:id,name_en,name_am,code')->withCount('units')
                ->find($organizationId, ['id', 'organization_type_id', 'code', 'name_en', 'name_am', 'status', 'logo_path', 'effective_from']);
            $units = $selected === null ? [] : $this->treeWithMeta((string) $selected->id, $user);
        }
        $stats = OrganizationUnit::withTrashed()->when($ids !== null, fn ($q) => $q->whereIn('organization_id', $ids))
            ->selectRaw('sum(case when deleted_at is null then 1 else 0 end) as total')
            ->selectRaw("sum(case when deleted_at is null and status = 'active' then 1 else 0 end) as active")
            ->selectRaw("sum(case when deleted_at is null and status <> 'active' then 1 else 0 end) as inactive")
            ->selectRaw('sum(case when deleted_at is not null then 1 else 0 end) as archived')
            ->selectRaw('count(distinct case when deleted_at is null then organization_id end) as organizations')->first();

        return Inertia::render('OrganizationUnits/Index', [
            'stats' => [
                'total' => (int) ($stats->total ?? 0), 'active' => (int) ($stats->active ?? 0), 'inactive' => (int) ($stats->inactive ?? 0),
                'archived' => (int) ($stats->archived ?? 0), 'organizations' => (int) ($stats->organizations ?? 0),
            ],
            'organizationTree' => $tree,
            'hasPublishedHierarchy' => $version !== null,
            'usingDraftFallback' => false,
            'usingFlatFallback' => $version === null,
            'selectedVersion' => $version === null ? null : $versionRow($version),
            'availableVersions' => HierarchyVersion::query()->whereIn('status', ['published', 'draft'])->latest('updated_at')->get()->map($versionRow)->values()->all(),
            'selectedOrganization' => $selected === null ? null : $selected->toArray() + ['organization_units_count' => $selected->units_count, 'logo_url' => null, 'has_logo' => false],
            'organizationUnits' => $units,
            'organizations' => $organizations->map(fn (Organization $o): array => $o->only(['id', 'code', 'name_en', 'name_am']))->values(),
            'unitTypes' => $this->unitTypes(),
            'can' => ['viewAny' => true, 'create' => $user->can('organization-units.create')],
        ]);
    }

    public function create(Request $request): Response
    {
        $ids = $this->scope->organizationIds($request->user());
        // The chosen organization's unit options are listed only inside the reader's scope (ADR 0009).
        abort_if($request->filled('organization_id') && ! $this->scope->allows($request->user(), (string) $request->get('organization_id')), 403);
        $selected = $request->filled('organization_id') ? Organization::query()->find($request->get('organization_id'), ['id', 'code', 'name_en', 'name_am']) : null;

        return Inertia::render('OrganizationUnits/Create', [
            'organizations' => Organization::query()->orderBy('name_en')->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))->get(['id', 'name_en', 'name_am', 'code']),
            'selectedOrg' => $selected,
            'parentUnits' => $selected === null ? [] : $this->options((string) $selected->id),
            'unitTypes' => $this->unitTypes(),
            'statusOptions' => $this->statusOptions(),
        ]);
    }

    public function store(Request $request, SaveOrganizationUnit $save): RedirectResponse
    {
        $unit = $save->execute(null, $this->validated($request, true), $request->user());

        return redirect()->route('organization-units.show', $unit)->with('success', __('organization-units.created_successfully'));
    }

    public function show(Request $request, OrganizationUnit $organizationUnit): Response
    {
        Gate::authorize('view', $organizationUnit);
        abort_unless($this->scope->allows($request->user(), (string) $organizationUnit->organization_id), 403);
        $organizationUnit->load(['organization:id,name_en,name_am,code', 'parent:id,name_en,name_am,code', 'children', 'unitType'])->loadCount('children');

        return Inertia::render('OrganizationUnits/Show', [
            'unit' => $this->resource($organizationUnit),
            'hostedContext' => null,
            'relationships' => [],
            'relationshipOptions' => ['targetTypes' => [], 'relationshipTypes' => [], 'statuses' => [], 'organizations' => [], 'institutionOffices' => [], 'organizationUnits' => []],
            'can' => ['manageRelationships' => false, 'updateRelationships' => false, 'deleteRelationships' => false],
        ]);
    }

    public function edit(OrganizationUnit $organizationUnit): Response
    {
        $organizationUnit->load(['organization:id,name_en,name_am,code', 'parent:id,name_en,name_am,code', 'unitType']);

        return Inertia::render('OrganizationUnits/Edit', [
            'unit' => $this->resource($organizationUnit),
            'unitTypes' => $this->unitTypes(),
            'statusOptions' => $this->statusOptions(),
            'parentOptions' => $this->options((string) $organizationUnit->organization_id),
        ]);
    }

    public function update(Request $request, OrganizationUnit $organizationUnit, SaveOrganizationUnit $save): RedirectResponse
    {
        Gate::authorize('update', $organizationUnit);
        $unit = $save->execute($organizationUnit, $this->validated($request, false), $request->user());

        return redirect()->route('organization-units.show', $unit)->with('success', __('organization-units.updated_successfully'));
    }

    public function archive(Request $request, OrganizationUnit $organizationUnit, SaveOrganizationUnit $save, AuditWriter $audit): RedirectResponse
    {
        Gate::authorize('archive', $organizationUnit);
        $save->execute($organizationUnit, ['status' => 'archived'], $request->user());
        $organizationUnit->delete();
        $audit->write('unit.archived', $request->user(), $organizationUnit, (string) $organizationUnit->organization_id, null, null, $request->string('reason')->toString() ?: null);

        return redirect()->route('organization-units.index')->with('success', __('recycle-bin.archived_successfully'));
    }

    public function restore(Request $request, string $organizationUnit, AuditWriter $audit): RedirectResponse
    {
        $unit = OrganizationUnit::onlyTrashed()->findOrFail($organizationUnit);
        if (! $this->scope->allows($request->user(), (string) $unit->organization_id)) {
            throw ValidationException::withMessages(['organization_id' => 'The organization is outside your organizations.']);
        }
        $unit->restore();
        $unit->update(['status' => 'active']);
        $audit->write('unit.restored', $request->user(), $unit, (string) $unit->organization_id);

        return redirect()->route('organization-units.show', $unit)->with('success', __('recycle-bin.restored_successfully'));
    }

    public function optionsFor(Request $request, Organization $organization): JsonResponse
    {
        abort_unless($this->scope->allows($request->user(), (string) $organization->id), 403);

        return response()->json($this->options((string) $organization->id));
    }

    public function tree(Request $request, Organization $organization): JsonResponse
    {
        abort_unless($this->scope->allows($request->user(), (string) $organization->id), 403);

        return response()->json($this->treeWithMeta((string) $organization->id, $request->user()));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';
        $data = $request->validate([
            'organization_id' => [$required, 'uuid', Rule::exists('organizations', 'id')],
            'parent_unit_id' => ['nullable', 'uuid'],
            'organization_unit_type_id' => [$required, 'uuid', Rule::exists('organization_unit_types', 'id')],
            'code' => ['nullable', 'string', 'max:64'],
            'name_en' => [$required, 'string', 'max:255'],
            'name_am' => ['nullable', 'string', 'max:255'],
            'description_en' => ['nullable', 'string', 'max:2000'],
            'description_am' => ['nullable', 'string', 'max:2000'],
            'status' => ['sometimes', Rule::in(SaveOrganizationUnit::STATUSES)],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        return array_filter($data, fn (mixed $value, string $key): bool => $value !== null || ! in_array($key, ['sort_order', 'code'], true) || ($key === 'code' && $creating), ARRAY_FILTER_USE_BOTH);
    }

    /** @return array<string, mixed> */
    private function resource(OrganizationUnit $unit): array
    {
        $user = request()->user();
        $inScope = $this->scope->allows($user, (string) $unit->organization_id);

        return [
            'id' => $unit->id,
            'organization_id' => $unit->organization_id,
            'organization' => $unit->organization?->only(['id', 'name_en', 'name_am', 'code']),
            'parent_unit_id' => $unit->parent_unit_id,
            'parent' => $unit->parent?->only(['id', 'name_en', 'name_am', 'code']),
            'organization_unit_type_id' => $unit->organization_unit_type_id,
            'unitType' => $unit->unitType?->only(['id', 'code', 'name_en', 'name_am']),
            'unit_type' => $unit->unit_type,
            'code' => $unit->code,
            'name_en' => $unit->name_en,
            'name_am' => $unit->name_am,
            'description_en' => $unit->description_en,
            'description_am' => $unit->description_am,
            'status' => $unit->status,
            'is_public_feedback_selectable' => (bool) $unit->is_public_feedback_selectable,
            'effective_from' => $unit->effective_from ? substr((string) $unit->effective_from, 0, 10) : null,
            'effective_to' => $unit->effective_to ? substr((string) $unit->effective_to, 0, 10) : null,
            'sort_order' => $unit->sort_order,
            'children_count' => $unit->children_count ?? $unit->children()->count(),
            'children' => $unit->relationLoaded('children') ? $unit->children->map(fn (OrganizationUnit $child): array => $child->only(['id', 'code', 'name_en', 'name_am', 'status', 'unit_type']))->values()->all() : [],
            'can' => [
                'update' => $inScope && $user->can('organization-units.update') && ! $unit->trashed(),
                'archive' => $inScope && $user->can('organization-units.archive') && ! $unit->trashed(),
                'restore' => $inScope && $user->can('organization-units.restore') && $unit->trashed(),
                'manageHierarchy' => false,
            ],
            'created_at' => $unit->created_at?->toISOString(),
            'updated_at' => $unit->updated_at?->toISOString(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function treeWithMeta(string $organizationId, User $user): array
    {
        $all = OrganizationUnit::withTrashed()->where('organization_id', $organizationId)->withCount('children')->orderBy('sort_order')->orderBy('name_en')->get();
        $types = OrganizationUnitType::withTrashed()->whereIn('id', $all->pluck('organization_unit_type_id')->filter()->unique())->get(['id', 'name_en', 'name_am'])->keyBy('id');
        $inScope = $this->scope->allows($user, $organizationId);
        $nest = function (?string $parentId, int $depth) use (&$nest, $all, $types, $inScope, $user): array {
            return $all->filter(fn (OrganizationUnit $u): bool => $u->parent_unit_id === $parentId)->map(fn (OrganizationUnit $u): array => array_merge($u->toArray(), [
                'depth' => $depth,
                'has_children' => $u->children_count > 0,
                'unit_type_label' => $types->get($u->organization_unit_type_id)?->name_en,
                'unit_type_name_am' => $types->get($u->organization_unit_type_id)?->name_am,
                'is_deleted' => $u->trashed(),
                'can' => [
                    'update' => $inScope && $user->can('organization-units.update') && ! $u->trashed(),
                    'archive' => $inScope && $user->can('organization-units.archive') && ! $u->trashed(),
                    'restore' => $inScope && $user->can('organization-units.restore') && $u->trashed(),
                ],
                'children' => $nest((string) $u->id, $depth + 1),
            ]))->values()->all();
        };

        return $nest(null, 0);
    }

    /** @return list<array{id: string, name_en: string, name_am: string|null, code: string, depth: int}> */
    private function options(string $organizationId): array
    {
        $all = OrganizationUnit::query()->where('organization_id', $organizationId)->where('status', 'active')->orderBy('sort_order')->orderBy('name_en')->get();
        $flatten = function (?string $parentId, int $depth) use (&$flatten, $all): array {
            $rows = [];
            foreach ($all->filter(fn (OrganizationUnit $u): bool => $u->parent_unit_id === $parentId) as $unit) {
                $rows[] = ['id' => $unit->id, 'name_en' => $unit->name_en, 'name_am' => $unit->name_am, 'code' => $unit->code, 'depth' => $depth];
                array_push($rows, ...$flatten((string) $unit->id, $depth + 1));
            }

            return $rows;
        };

        return $flatten(null, 0);
    }

    private function unitTypes(): Collection
    {
        return OrganizationUnitType::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name_en')->get(['id', 'code', 'name_en', 'name_am']);
    }

    /** @return list<array{value: string, label: string}> */
    private function statusOptions(): array
    {
        return array_map(fn (string $status): array => ['value' => $status, 'label' => ucfirst($status)], SaveOrganizationUnit::STATUSES);
    }
}

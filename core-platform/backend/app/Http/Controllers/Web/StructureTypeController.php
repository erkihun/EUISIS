<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\SaveStructureType;
use App\Http\Controllers\Controller;
use App\Models\OrganizationType;
use App\Models\OrganizationUnitType;
use App\Services\AuditWriter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** EUISIS OrganizationTypes/* and OrganizationUnitTypes/* over the Core tables. */
class StructureTypeController extends Controller
{
    public function organizationTypes(Request $request): Response
    {
        $user = $request->user();
        $status = $request->string('status')->toString();
        $filters = [
            'search' => $request->string('search')->trim()->toString(),
            'status' => in_array($status, ['active', 'inactive'], true) ? $status : '',
            'category' => $request->string('category')->trim()->toString(),
        ];

        $types = OrganizationType::query()->withCount('organizations')
            ->when($filters['search'] !== '', fn ($q) => $q->where(fn ($inner) => $inner->where('code', 'like', '%'.$filters['search'].'%')
                ->orWhere('name_en', 'like', '%'.$filters['search'].'%')->orWhere('name_am', 'like', '%'.$filters['search'].'%')))
            ->when($filters['status'] !== '', fn ($q) => $q->where('is_active', $filters['status'] === 'active'))
            ->when($filters['category'] !== '', fn ($q) => $q->where('category', $filters['category']))
            ->orderBy('level_order')->orderBy('sort_order')->orderBy('name_en')
            ->paginate(25)->withQueryString()
            ->through(fn (OrganizationType $type): array => $this->organizationTypeRow($type, $user->can('organization-types.update')));

        return Inertia::render('OrganizationTypes/Index', [
            'types' => $types,
            'filters' => $filters,
            'filterOptions' => ['categories' => OrganizationType::query()->whereNotNull('category')->distinct()->orderBy('category')->pluck('category')->all()],
            'can' => ['create' => $user->can('organization-types.create')],
        ]);
    }

    public function createOrganizationType(): Response
    {
        return Inertia::render('OrganizationTypes/Create', ['allTypes' => $this->allOrganizationTypes()]);
    }

    public function showOrganizationType(Request $request, OrganizationType $organizationType): Response
    {
        $row = $this->organizationTypeRow($organizationType->loadCount('organizations'), $request->user()->can('organization-types.update'));

        return Inertia::render('OrganizationTypes/Show', [
            'type' => $row + [
                'description_am' => $organizationType->description_am,
                'created_at' => $organizationType->created_at?->toISOString(),
                'updated_at' => $organizationType->updated_at?->toISOString(),
            ],
        ]);
    }

    public function editOrganizationType(OrganizationType $organizationType): Response
    {
        return Inertia::render('OrganizationTypes/Edit', ['type' => $organizationType, 'allTypes' => $this->allOrganizationTypes()]);
    }

    public function storeOrganizationType(Request $request, SaveStructureType $save): RedirectResponse
    {
        $save->execute(OrganizationType::class, null, $this->organizationTypeData($request), $request->user());

        return to_route('organization-types.index')->with('success', __('organization-types.created'));
    }

    public function updateOrganizationType(Request $request, OrganizationType $organizationType, SaveStructureType $save): RedirectResponse
    {
        $save->execute(OrganizationType::class, (string) $organizationType->id, $this->organizationTypeData($request), $request->user());

        return to_route('organization-types.index')->with('success', __('organization-types.updated'));
    }

    public function unitTypes(Request $request): Response
    {
        $user = $request->user();
        $status = $request->string('status')->toString();
        $filters = [
            'search' => $request->string('search')->trim()->toString(),
            'status' => in_array($status, ['active', 'archived'], true) ? $status : '',
        ];

        $types = OrganizationUnitType::query()->withTrashed()
            ->when($filters['search'] !== '', fn ($q) => $q->where(fn ($inner) => $inner->where('code', 'like', '%'.$filters['search'].'%')
                ->orWhere('name_en', 'like', '%'.$filters['search'].'%')->orWhere('name_am', 'like', '%'.$filters['search'].'%')))
            ->when($filters['status'] === 'active', fn ($q) => $q->whereNull('deleted_at')->where('is_active', true))
            ->when($filters['status'] === 'archived', fn ($q) => $q->where(fn ($inner) => $inner->whereNotNull('deleted_at')->orWhere('is_active', false)))
            ->orderBy('sort_order')->orderBy('name_en')
            ->paginate(25)->withQueryString()
            ->through(fn (OrganizationUnitType $type): array => [
                'id' => $type->id,
                'code' => $type->code,
                'prefix' => $type->prefix,
                'name_en' => $type->name_en,
                'name_am' => $type->name_am,
                'description_en' => $type->description_en,
                'is_active' => $type->is_active,
                'sort_order' => $type->sort_order,
                'deleted_at' => $type->deleted_at?->toISOString(),
                'can' => $this->unitTypeCan($type),
            ]);

        return Inertia::render('OrganizationUnitTypes/Index', [
            'types' => $types,
            'filters' => $filters,
            'can' => ['create' => $user->can('organization-unit-types.create')],
        ]);
    }

    public function createUnitType(): Response
    {
        return Inertia::render('OrganizationUnitTypes/Create');
    }

    public function showUnitType(string $organizationUnitType): Response
    {
        $type = OrganizationUnitType::withTrashed()->findOrFail($organizationUnitType);

        return Inertia::render('OrganizationUnitTypes/Show', [
            'type' => $type->only(['id', 'code', 'prefix', 'name_en', 'name_am', 'description_en', 'description_am', 'sort_order', 'is_active', 'metadata']) + [
                'can' => $this->unitTypeCan($type),
                'created_at' => $type->created_at?->toISOString(),
                'updated_at' => $type->updated_at?->toISOString(),
            ],
        ]);
    }

    public function editUnitType(string $organizationUnitType): Response
    {
        return Inertia::render('OrganizationUnitTypes/Edit', ['type' => OrganizationUnitType::withTrashed()->findOrFail($organizationUnitType)]);
    }

    public function storeUnitType(Request $request, SaveStructureType $save): RedirectResponse
    {
        $save->execute(OrganizationUnitType::class, null, $this->unitTypeData($request), $request->user());

        return to_route('organization-unit-types.index')->with('flash', ['message' => __('Organization unit type created.'), 'type' => 'success']);
    }

    public function updateUnitType(Request $request, string $organizationUnitType, SaveStructureType $save): RedirectResponse
    {
        $save->execute(OrganizationUnitType::class, $organizationUnitType, $this->unitTypeData($request), $request->user());

        return to_route('organization-unit-types.index')->with('flash', ['message' => __('Organization unit type updated.'), 'type' => 'success']);
    }

    public function archiveUnitType(Request $request, string $organizationUnitType, AuditWriter $audit): RedirectResponse
    {
        $type = OrganizationUnitType::query()->findOrFail($organizationUnitType);
        $type->delete();
        $audit->write('unit_type.archived', $request->user(), $type, null, null, null, $request->string('reason')->toString() ?: null);

        return to_route('organization-unit-types.index')->with('flash', ['message' => __('recycle-bin.archived_successfully'), 'type' => 'success']);
    }

    public function restoreUnitType(Request $request, string $organizationUnitType, AuditWriter $audit): RedirectResponse
    {
        $type = OrganizationUnitType::onlyTrashed()->findOrFail($organizationUnitType);
        $type->restore();
        $audit->write('unit_type.restored', $request->user(), $type, null);

        return to_route('organization-unit-types.index')->with('flash', ['message' => __('recycle-bin.restored_successfully'), 'type' => 'success']);
    }

    /** @return array<string, mixed> */
    private function organizationTypeRow(OrganizationType $type, bool $canUpdate): array
    {
        return [
            'id' => $type->id,
            'code' => $type->code,
            'prefix' => $type->prefix,
            'name_en' => $type->name_en,
            'name_am' => $type->name_am,
            'description_en' => $type->description_en,
            'is_active' => $type->is_active,
            'sort_order' => $type->sort_order,
            'level_order' => $type->level_order,
            'category' => $type->category,
            'parent_allowed_types' => $type->parent_allowed_types ?? [],
            'organizations_count' => (int) $type->organizations_count,
            'can' => ['update' => $canUpdate, 'delete' => false],
        ];
    }

    /** @return array{update: bool, archive: bool, restore: bool} */
    private function unitTypeCan(OrganizationUnitType $type): array
    {
        $user = request()->user();

        return [
            'update' => $type->deleted_at === null && $user->can('organization-unit-types.update'),
            'archive' => $type->deleted_at === null && $user->can('organization-unit-types.archive'),
            'restore' => $type->deleted_at !== null && $user->can('organization-unit-types.restore'),
        ];
    }

    /** @return list<array{code: string, name_en: string}> */
    private function allOrganizationTypes(): array
    {
        return OrganizationType::query()->orderBy('level_order')->orderBy('name_en')->get(['code', 'name_en'])
            ->map(fn (OrganizationType $type): array => ['code' => $type->code, 'name_en' => $type->name_en])->values()->all();
    }

    /** @return array<string, mixed> */
    private function organizationTypeData(Request $request): array
    {
        return $this->withoutBlankOrder($request->validate([
            'code' => ['required', 'string', 'max:64'],
            'prefix' => ['nullable', 'string', 'max:20'],
            'name_en' => ['required', 'string', 'max:255'],
            'name_am' => ['nullable', 'string', 'max:255'],
            'description_en' => ['nullable', 'string', 'max:2000'],
            'description_am' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'level_order' => ['nullable', 'integer', 'min:1', 'max:99'],
            'category' => ['nullable', Rule::in(['root', 'functional', 'geographic', 'service_provider', 'office', 'unit', 'other'])],
            'parent_allowed_types' => ['nullable', 'array'],
            'parent_allowed_types.*' => ['string', 'max:64'],
        ]));
    }

    /** @return array<string, mixed> */
    private function unitTypeData(Request $request): array
    {
        return $this->withoutBlankOrder($request->validate([
            'code' => ['required', 'string', 'max:64'],
            'prefix' => ['nullable', 'string', 'max:64'],
            'name_en' => ['required', 'string', 'max:255'],
            'name_am' => ['nullable', 'string', 'max:255'],
            'description_en' => ['nullable', 'string', 'max:2000'],
            'description_am' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]));
    }

    /**
     * An order left blank keeps the column default.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withoutBlankOrder(array $data): array
    {
        return array_filter($data, fn (mixed $value, string $key): bool => $value !== null || ! in_array($key, ['sort_order', 'level_order'], true), ARRAY_FILTER_USE_BOTH);
    }
}

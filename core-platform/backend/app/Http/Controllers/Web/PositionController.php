<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\ChangePositionLifecycle;
use App\Actions\ManageJobMasters;
use App\Actions\SaveEstablishment;
use App\Actions\SavePosition;
use App\Http\Controllers\Controller;
use App\Models\EmployeeAssignment;
use App\Models\HierarchyVersion;
use App\Models\JobGrade;
use App\Models\Occupation;
use App\Models\Organization;
use App\Models\OrganizationEdge;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Models\PositionEstablishment;
use App\Models\User;
use App\Services\AuditWriter;
use App\Services\CoreSettings;
use App\Services\OrganizationScope;
use App\Services\SeatCapacity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** EUISIS Positions/* and PositionEstablishments/* over the Core tables. */
class PositionController extends Controller
{
    private const BREAKDOWN_LIMIT = 8;

    public function __construct(private readonly OrganizationScope $scope, private readonly SeatCapacity $seats) {}

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $ids = $this->scope->organizationIds($user);
        $organizations = Organization::query()->with('type:id,name_en,name_am,code')->withCount('units')->orderBy('name_en')
            ->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))
            ->get(['id', 'organization_type_id', 'code', 'name_en', 'name_am', 'status', 'logo_path', 'effective_from']);
        $selected = null;
        $unitTree = [];
        $selectedUnit = null;
        $positions = collect();

        if ($request->filled('organization_id')) {
            $organizationId = $request->string('organization_id')->toString();
            abort_unless($this->scope->allows($user, $organizationId), 403);
            $selected = Organization::query()->with('type:id,name_en,name_am,code')->withCount('units')
                ->find($organizationId, ['id', 'organization_type_id', 'code', 'name_en', 'name_am', 'status', 'logo_path', 'effective_from']);
            if ($selected !== null) {
                $units = OrganizationUnit::query()->where('organization_id', $organizationId)->orderBy('sort_order')->orderBy('name_en')->get(['id', 'code', 'name_en', 'name_am', 'parent_unit_id']);
                $build = function (?string $parentId, int $depth) use (&$build, $units): array {
                    return $units->filter(fn ($u): bool => $u->parent_unit_id === $parentId)->map(fn ($u): array => [
                        'id' => $u->id, 'code' => $u->code, 'name_en' => $u->name_en, 'name_am' => $u->name_am, 'depth' => $depth, 'children' => $build((string) $u->id, $depth + 1),
                    ])->values()->all();
                };
                $unitTree = $build(null, 0);
                $unitId = $request->string('organization_unit_id')->toString() ?: null;
                if ($unitId !== null && ($unit = $units->firstWhere('id', $unitId)) !== null) {
                    $selectedUnit = $unit->only(['id', 'name_en', 'name_am']);
                }
                $search = $request->string('search')->toString();
                $occupancy = $request->string('occupancy')->toString();
                $positions = Position::query()->with('organization:id,name_en,name_am', 'unit:id,name_en,name_am,code')->withCount('assignments')
                    ->where('organization_id', $organizationId)
                    ->when($unitId, fn ($q) => $q->where('organization_unit_id', $unitId))
                    ->when($search !== '', fn ($q) => $q->where(fn ($inner) => $inner->where('job_position_code', 'like', "%{$search}%")->orWhere('old_code', 'like', "%{$search}%")
                        ->orWhere('title_en', 'like', "%{$search}%")->orWhere('title_am', 'like', "%{$search}%")->orWhere('bpr_name', 'like', "%{$search}%")))
                    ->when($request->string('job_family')->toString() !== '', fn ($q) => $q->where('job_family', $request->string('job_family')->toString()))
                    ->when($request->string('grade_level')->toString() !== '', fn ($q) => $q->where('grade_level', $request->string('grade_level')->toString()))
                    ->when($request->filled('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
                    ->when($occupancy === 'held', fn ($q) => $q->whereHas('holders'))
                    ->when($occupancy === 'vacant', fn ($q) => $q->whereDoesntHave('holders'))
                    ->orderBy('job_position_code')->get();
            }
        }
        $establishments = PositionEstablishment::query()->whereIn('position_id', $positions->pluck('id'))->whereIn('status', ['draft', 'approved'])
            ->get(['id', 'position_id', 'status', 'approved_slots', 'establishment_number'])->groupBy('position_id')
            ->map(fn (Collection $group) => $group->sortBy(fn (PositionEstablishment $e): int => $e->status === 'approved' ? 0 : 1)->first());

        return Inertia::render('Positions/Index', [
            'organizationStructure' => $this->structure($organizations, $selected?->id !== null ? (string) $selected->id : null),
            'isOrganizationScoped' => $ids !== null && count($ids) <= 1,
            'organizationTree' => $this->organizationTree($organizations),
            'hasPublishedHierarchy' => HierarchyVersion::current() !== null,
            'selectedOrganization' => $selected === null ? null : $selected->toArray() + ['organization_units_count' => $selected->units_count, 'logo_url' => null, 'has_logo' => false],
            'organizationUnits' => $unitTree,
            'selectedUnit' => $selectedUnit,
            'positions' => $positions->map(function (Position $position) use ($establishments): array {
                $establishment = $establishments->get($position->id);

                return $this->resource($position) + ['establishment' => $establishment?->only(['id', 'status', 'approved_slots', 'establishment_number'])];
            })->values()->all(),
            'filters' => $request->only(['search', 'job_family', 'grade_level', 'is_active', 'occupancy']),
            'can' => ['create' => $user->can('positions.create'), 'approve_establishment' => $user->can('position-establishments.approve')],
        ]);
    }

    public function status(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $ids = $this->scope->organizationIds($user);
        $search = $request->string('search')->toString();
        $base = Position::query()->when($ids !== null, fn ($q) => $q->whereIn('organization_id', $ids))
            ->when($search !== '', fn ($q) => $q->where(fn ($inner) => $inner->where('job_position_code', 'like', "%{$search}%")->orWhere('old_code', 'like', "%{$search}%")
                ->orWhere('title_en', 'like', "%{$search}%")->orWhere('title_am', 'like', "%{$search}%")->orWhere('bpr_name', 'like', "%{$search}%")
                ->orWhere('grade_level', 'like', "%{$search}%")->orWhere('job_family', 'like', "%{$search}%")))
            ->when($request->string('organization_id')->toString() !== '', fn ($q) => $q->where('organization_id', $request->string('organization_id')->toString()))
            ->when($request->string('organization_unit_id')->toString() !== '', fn ($q) => $q->where('organization_unit_id', $request->string('organization_unit_id')->toString()))
            ->when($request->string('job_family')->toString() !== '', fn ($q) => $q->where('job_family', $request->string('job_family')->toString()))
            ->when($request->string('grade_level')->toString() !== '', fn ($q) => $q->where('grade_level', $request->string('grade_level')->toString()))
            ->when($request->filled('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')));

        // Seats per position: approved establishment slots in force, else the Workforce default (SeatCapacity).
        $all = (clone $base)->withCount('holders')->get(['id', 'organization_id', 'organization_unit_id', 'grade_level', 'job_family']);
        $capacity = $this->capacities($all->pluck('id')->all());
        $rows = $all->map(fn (Position $p): array => ['position' => $p, 'total' => $capacity($p->id), 'filled' => (int) $p->holders_count]);
        $total = (int) $rows->sum('total');
        $filled = (int) $rows->sum(fn (array $row): int => min($row['filled'], $row['total']));
        $grouping = $ids !== null && count($ids) <= 1 ? 'unit' : 'organization';
        $breakdown = function (string $column, array $names) use ($rows): array {
            $groups = $rows->groupBy(fn (array $row): string => (string) ($row['position']->{$column} ?: '__unassigned__'))
                ->map(fn (Collection $group, string $key): array => [
                    'key' => $key,
                    'label_en' => $key === '__unassigned__' ? null : ($names[$key][0] ?? $key),
                    'label_am' => $key === '__unassigned__' ? null : ($names[$key][1] ?? $key),
                    'total' => (int) $group->sum('total'),
                    'filled' => (int) $group->sum(fn (array $row): int => min($row['filled'], $row['total'])),
                ])->map(fn (array $group): array => $group + ['vacant' => max(0, $group['total'] - $group['filled'])])
                ->sortByDesc('total')->values();
            if ($groups->count() <= self::BREAKDOWN_LIMIT) {
                return $groups->all();
            }
            $rest = $groups->slice(self::BREAKDOWN_LIMIT);

            return $groups->take(self::BREAKDOWN_LIMIT)->push(['key' => '__other__', 'label_en' => null, 'label_am' => null,
                'total' => (int) $rest->sum('total'), 'filled' => (int) $rest->sum('filled'), 'vacant' => (int) $rest->sum('vacant')])->all();
        };
        $names = fn (string $model, string $column): array => $model::withTrashed()->whereIn('id', $rows->pluck('position.'.$column)->filter()->unique())
            ->get(['id', 'name_en', 'name_am'])->mapWithKeys(fn ($m): array => [(string) $m->id => [$m->name_en, $m->name_am]])->all();

        $page = (clone $base)->with(['organization:id,name_en,name_am', 'unit:id,name_en,name_am'])->withCount('holders')
            ->orderBy('organization_id')->orderBy('organization_unit_id')->orderBy('job_position_code')
            ->paginate(min(max($request->integer('per_page', 15), 1), 100))->withQueryString();
        $establishments = PositionEstablishment::query()->where('status', PositionEstablishment::APPROVED)->whereIn('position_id', $page->getCollection()->modelKeys())
            ->get(['id', 'establishment_number', 'position_id'])->groupBy('position_id');
        $items = $page->getCollection()->map(function (Position $position) use ($establishments, $capacity): array {
            $total = $capacity($position->id);
            $filledHere = min((int) $position->holders_count, $total);

            return [
                'id' => $position->id,
                'position_id' => $position->id,
                'establishment_id' => $establishments->get($position->id)?->first()?->id,
                'establishment_number' => $establishments->get($position->id, collect())->pluck('establishment_number')->filter()->implode(', '),
                'job_position_code' => $position->job_position_code,
                'title_en' => $position->title_en,
                'title_am' => $position->title_am,
                'grade_level' => $position->grade_level,
                'job_family' => $position->job_family,
                'organization_name_en' => $position->organization?->name_en,
                'organization_name_am' => $position->organization?->name_am,
                'department_name_en' => $position->unit?->name_en ?? $position->organization?->name_en,
                'department_name_am' => $position->unit?->name_am ?? $position->organization?->name_am,
                'is_active' => (bool) $position->is_active,
                'total_positions' => $total,
                'filled_positions' => $filledHere,
                'vacant_positions' => max(0, $total - $filledHere),
            ];
        })->values()->all();

        return Inertia::render('Positions/Status', [
            'summary' => [
                'total_positions' => $total,
                'filled_positions' => $filled,
                'vacant_positions' => max(0, $total - $filled),
                'occupancy_rate' => $total > 0 ? round($filled / $total * 100, 1) : 0.0,
            ],
            'breakdowns' => [
                'grouping' => $grouping,
                'by_group' => $grouping === 'unit'
                    ? $breakdown('organization_unit_id', $names(OrganizationUnit::class, 'organization_unit_id'))
                    : $breakdown('organization_id', $names(Organization::class, 'organization_id')),
                'by_grade_level' => $breakdown('grade_level', []),
                'by_job_family' => $breakdown('job_family', []),
            ],
            'positions' => [
                'data' => $items,
                'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem()],
            ],
            'organizations' => Organization::query()->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))->orderBy('name_en')->get(['id', 'name_en', 'name_am']),
            'organizationUnits' => OrganizationUnit::query()->when($ids !== null, fn ($q) => $q->whereIn('organization_id', $ids))
                ->when($request->string('organization_id')->toString() !== '', fn ($q) => $q->where('organization_id', $request->string('organization_id')->toString()))
                ->orderBy('name_en')->get(['id', 'organization_id', 'name_en', 'name_am']),
            'jobFamilies' => (clone $base)->whereNotNull('job_family')->distinct()->orderBy('job_family')->pluck('job_family')->filter()->values()->all(),
            'isOrganizationScoped' => $grouping === 'unit',
            'filters' => $request->only(['search', 'organization_id', 'organization_unit_id', 'job_family', 'grade_level', 'is_active', 'per_page']),
        ]);
    }

    public function create(Request $request): Response
    {
        $organizationId = $request->string('organization_id')->toString() ?: null;
        $unitId = $request->string('organization_unit_id')->toString() ?: null;
        if ($unitId !== null) {
            $organizationId ??= OrganizationUnit::query()->whereKey($unitId)->value('organization_id');
        }
        abort_if($organizationId !== null && ! $this->scope->allows($request->user(), (string) $organizationId), 403);

        return Inertia::render('Positions/Create', [
            'organizations' => $this->organizationOptions($request->user()),
            'organizationUnits' => $organizationId === null ? [] : OrganizationUnit::query()->where('organization_id', $organizationId)->orderBy('name_en')
                ->get(['id', 'name_en', 'name_am', 'code', 'organization_unit_type_id'])
                ->map(fn (OrganizationUnit $unit): array => $unit->toArray() + ['owner_organization' => null, 'host_organization' => null])->all(),
            'occupations' => $this->occupationOptions(),
            'gradeLevels' => $this->gradeLevels(),
            'jobMasters' => $this->masterOptions(),
            'selectedOrganizationId' => $organizationId,
            'selectedOrganizationUnitId' => $unitId,
        ]);
    }

    public function store(Request $request, SavePosition $save): RedirectResponse
    {
        $position = $save->execute(null, $this->validated($request, true), $request->user());

        return to_route('positions.show', $position)->with('flash', ['message' => __('positions.created_successfully'), 'type' => 'success']);
    }

    public function show(Request $request, Position $position): Response
    {
        Gate::authorize('view', $position);
        abort_unless($this->scope->allows($request->user(), (string) $position->organization_id), 403);
        $position->load('organization:id,name_en,name_am', 'unit:id,name_en,name_am,code', 'grade', 'level', 'occupation', 'profession', 'employmentType', 'category')
            ->loadCount(['assignments', 'holders']);

        return Inertia::render('Positions/Show', [
            'position' => $this->resource($position),
            'lifecycle' => $this->lifecycle($request->user(), $position),
            'movementHistory' => [],
            'positionServices' => [],
            'currentPerformancePlans' => [],
            'historicalPerformancePlans' => [],
            'canViewPerformancePlans' => false,
            'canViewPositionServices' => false,
        ]);
    }

    public function edit(Request $request, Position $position): Response
    {
        $position->load('organization:id,name_en,name_am', 'unit:id,name_en,name_am,code');

        return Inertia::render('Positions/Edit', [
            'position' => $this->resource($position),
            'organizations' => $this->organizationOptions($request->user()),
            'organizationUnits' => $position->organization_id === null ? [] : OrganizationUnit::query()->where('organization_id', $position->organization_id)
                ->orderBy('name_en')->get(['id', 'name_en', 'name_am', 'code', 'organization_unit_type_id'])->toArray(),
            'occupations' => $this->occupationOptions(),
            'gradeLevels' => $this->gradeLevels(),
            'jobMasters' => $this->masterOptions(),
        ]);
    }

    public function update(Request $request, Position $position, SavePosition $save): RedirectResponse
    {
        Gate::authorize('update', $position);
        $save->execute($position, $this->validated($request, false), $request->user());

        return to_route('positions.show', $position)->with('flash', ['message' => __('positions.updated_successfully'), 'type' => 'success']);
    }

    /** One lifecycle step (ADR 0010): approve, suspend, reinstate, abolish or archive. */
    public function lifecycleStep(Request $request, Position $position, ChangePositionLifecycle $lifecycle): RedirectResponse
    {
        $data = $request->validate(['step' => ['required', Rule::in(ChangePositionLifecycle::steps())], 'reason' => ['nullable', 'string', 'max:2000']]);
        $lifecycle->execute($position, $data['step'], $data['reason'] ?? null, $request->user());

        return back()->with('flash', ['message' => __('positions.lifecycle_changed'), 'type' => 'success']);
    }

    public function archive(Request $request, Position $position, AuditWriter $audit): RedirectResponse
    {
        Gate::authorize('archive', $position);
        if (! $this->scope->allows($request->user(), (string) $position->organization_id)) {
            throw ValidationException::withMessages(['organization_id' => 'The organization is outside your organizations.']);
        }
        if ($position->holders()->exists()) {
            throw ValidationException::withMessages(['position' => 'A position someone holds cannot be archived.']);
        }
        $position->update(['is_active' => false]);
        $position->delete();
        $audit->write('position.archived', $request->user(), $position, (string) $position->organization_id, null, null, $request->string('reason')->toString() ?: null);

        return to_route('positions.index')->with('flash', ['message' => __('recycle-bin.archived_successfully'), 'type' => 'success']);
    }

    public function restore(Request $request, string $position, AuditWriter $audit): RedirectResponse
    {
        $model = Position::onlyTrashed()->findOrFail($position);
        abort_unless($this->scope->allows($request->user(), (string) $model->organization_id), 403);
        $model->restore();
        $audit->write('position.restored', $request->user(), $model, (string) $model->organization_id);

        return back()->with('flash', ['message' => __('recycle-bin.restored_successfully'), 'type' => 'success']);
    }

    /** One seat approved now for a position without an establishment (EUISIS "approve establishment"). */
    public function approveEstablishment(Request $request, Position $position, SaveEstablishment $establishments): RedirectResponse
    {
        if (PositionEstablishment::query()->where('position_id', $position->id)->where('status', PositionEstablishment::APPROVED)->exists()) {
            return back()->with('flash', ['message' => __('position-establishments.alreadyApproved'), 'type' => 'info']);
        }
        $establishments->approve($position, ['approved_slots' => 1, 'effective_from' => now()->toDateString()], $request->user());

        return back()->with('flash', ['message' => __('position-establishments.approved'), 'type' => 'success']);
    }

    public function establishments(Request $request): Response
    {
        $ids = $this->scope->organizationIds($request->user());
        $status = $request->string('status')->toString();
        $filters = [
            'search' => $request->string('search')->trim()->toString(),
            'status' => in_array($status, PositionEstablishment::STATUSES, true) ? $status : '',
            'organization_id' => $request->string('organization_id')->toString(),
        ];

        $page = PositionEstablishment::query()->with(['organization', 'position', 'approver'])
            ->when($ids !== null, fn ($q) => $q->whereIn('organization_id', $ids))
            ->when($filters['search'] !== '', fn ($q) => $q->where('establishment_number', 'like', '%'.$filters['search'].'%'))
            ->when($filters['status'] !== '', fn ($q) => $q->where('status', $filters['status']))
            ->when($filters['organization_id'] !== '', fn ($q) => $q->where('organization_id', $filters['organization_id']))
            ->latest()->paginate(20)->withQueryString();
        $page->setCollection($page->getCollection()->map(fn (PositionEstablishment $e): array => $this->establishmentPayload($e)));

        return Inertia::render('PositionEstablishments/Index', [
            'establishments' => $page,
            'filters' => $filters,
            'filterOptions' => ['statuses' => PositionEstablishment::STATUSES],
        ]);
    }

    public function showEstablishment(Request $request, PositionEstablishment $positionEstablishment): Response
    {
        abort_unless($this->scope->allows($request->user(), (string) $positionEstablishment->organization_id), 403);
        $positionEstablishment->load(['organization', 'organizationUnit', 'position', 'approver', 'occupancies.employee']);
        $can = $this->establishmentCan($request->user(), $positionEstablishment);

        return Inertia::render('PositionEstablishments/Show', [
            'establishment' => $this->establishmentPayload($positionEstablishment) + [
                'organization_unit' => $positionEstablishment->organizationUnit?->only(['id', 'name_en', 'name_am']),
                'occupancies' => $positionEstablishment->occupancies->map(fn ($o): array => [
                    'id' => $o->id, 'status' => $o->status, 'occupied_from' => $o->occupied_from?->toDateString(),
                    'employee' => $o->employee === null ? null : ['name_en' => $o->employee->name_en ?? $o->employee->full_name],
                ])->values(),
                'vacancy_announcements' => [],
                'can' => $can,
            ],
            'can' => $can,
        ]);
    }

    public function editEstablishment(PositionEstablishment $positionEstablishment): Response
    {
        return Inertia::render('PositionEstablishments/Edit', ['establishment' => $this->establishmentPayload($positionEstablishment)]);
    }

    public function updateEstablishment(Request $request, PositionEstablishment $positionEstablishment, AuditWriter $audit): RedirectResponse
    {
        abort_unless($this->scope->allows($request->user(), (string) $positionEstablishment->organization_id), 403);
        $data = $request->validate([
            'approved_slots' => ['required', 'integer', 'min:1', 'max:10000'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'approval_reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $holders = $this->seats->holders((string) $positionEstablishment->position_id);
        if ($positionEstablishment->status === PositionEstablishment::APPROVED && $data['approved_slots'] < $holders) {
            throw ValidationException::withMessages(['approved_slots' => "The position has {$holders} holders; the seats cannot go below that."]);
        }
        $old = $positionEstablishment->only(array_keys($data));
        $positionEstablishment->update($data);
        $audit->write('establishment.updated', $request->user(), $positionEstablishment, (string) $positionEstablishment->organization_id, $old, $positionEstablishment->only(array_keys($data)));

        return to_route('position-establishments.show', $positionEstablishment)->with('flash', ['message' => __('position-establishments.updated'), 'type' => 'success']);
    }

    public function approveDraftEstablishment(Request $request, PositionEstablishment $positionEstablishment, AuditWriter $audit): RedirectResponse
    {
        abort_unless($this->scope->allows($request->user(), (string) $positionEstablishment->organization_id), 403);
        $positionEstablishment->update(['status' => PositionEstablishment::APPROVED, 'approved_by' => $request->user()->id, 'approved_at' => now()]);
        $audit->write('establishment.approved', $request->user(), $positionEstablishment, (string) $positionEstablishment->organization_id);

        return back()->with('flash', ['message' => __('position-establishments.approved'), 'type' => 'success']);
    }

    public function archiveEstablishment(Request $request, PositionEstablishment $positionEstablishment, SaveEstablishment $establishments): RedirectResponse
    {
        $establishments->end($positionEstablishment, now()->toDateString(), $request->user());

        return to_route('position-establishments.index')->with('flash', ['message' => __('position-establishments.archived'), 'type' => 'success']);
    }

    /** @return array<string, mixed> */
    private function resource(Position $position): array
    {
        $user = request()->user();
        $inScope = $this->scope->allows($user, (string) $position->organization_id);

        return [
            'id' => $position->id,
            'job_position_code' => $position->job_position_code,
            'old_code' => $position->old_code,
            'title_en' => $position->title_en,
            'title_am' => $position->title_am,
            'standard_name' => $position->title_en,
            'standard_name_en' => $position->title_en,
            'standard_name_am' => $position->title_am,
            'bpr_name' => $position->bpr_name,
            'description_en' => $position->description_en,
            'description_am' => $position->description_am,
            'organization_id' => $position->organization_id,
            'organization' => $position->organization?->only(['id', 'name_en', 'name_am']),
            'organization_unit_id' => $position->organization_unit_id,
            'organization_unit' => $position->unit?->only(['id', 'name_en', 'name_am', 'code']),
            'occupation_id' => $position->occupation_id,
            'occupation' => $position->relationLoaded('occupation') ? $position->occupation?->only(['id', 'code', 'name_en', 'name_am']) : null,
            'grade_level' => $position->grade_level,
            'job_grade_id' => $position->job_grade_id,
            'job_level_id' => $position->job_level_id,
            'profession_id' => $position->profession_id,
            'employment_type_id' => $position->employment_type_id,
            'position_category_id' => $position->position_category_id,
            'lifecycle_status' => $position->lifecycle_status,
            'job_family' => $position->job_family,
            'is_active' => $position->is_active,
            'effective_from' => $position->effective_from?->toDateString(),
            'effective_to' => $position->effective_to?->toDateString(),
            'assignments_count' => $position->assignments_count,
            'created_at' => $position->created_at?->toISOString(),
            'updated_at' => $position->updated_at?->toISOString(),
            'can' => [
                'view' => true,
                'update' => $inScope && $user->can('positions.update') && ! $position->trashed(),
                'move' => false,
                'archive' => $inScope && $user->can('positions.archive') && ! $position->trashed(),
                'restore' => $inScope && $user->can('positions.restore') && $position->trashed(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';
        $data = $request->validate([
            'organization_id' => ['nullable', 'uuid', Rule::exists('organizations', 'id')],
            'organization_unit_id' => ['nullable', 'uuid', Rule::exists('organization_units', 'id')],
            'job_position_code' => ['nullable', 'string', 'max:64'],
            'old_code' => ['nullable', 'string', 'max:100'],
            'title_en' => [$required, 'string', 'max:255'],
            'title_am' => ['nullable', 'string', 'max:255'],
            'bpr_name' => ['nullable', 'string', 'max:255'],
            'description_en' => ['nullable', 'string', 'max:5000'],
            'description_am' => ['nullable', 'string', 'max:5000'],
            'grade_level' => ['nullable', 'string', 'max:255'],
            'job_grade_id' => ['nullable', 'uuid'],
            'job_level_id' => ['nullable', 'uuid'],
            'occupation_id' => ['nullable', 'uuid'],
            'profession_id' => ['nullable', 'uuid'],
            'employment_type_id' => ['nullable', 'uuid'],
            'position_category_id' => ['nullable', 'uuid'],
            'job_family' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date'],
        ]);
        if (! $creating && ($data['job_position_code'] ?? null) === null) {
            unset($data['job_position_code']);
        }

        return $data;
    }

    /**
     * Seats in force per position in one query: approved establishment slots, else the Workforce default (SeatCapacity).
     *
     * @param  list<string>  $positionIds
     * @return \Closure(string): int
     */
    private function capacities(array $positionIds): \Closure
    {
        $today = now()->toDateString();
        $approved = PositionEstablishment::query()->whereIn('position_id', $positionIds)->where('status', PositionEstablishment::APPROVED)
            ->whereDate('effective_from', '<=', $today)->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $today))
            ->groupBy('position_id')->selectRaw('position_id, sum(approved_slots) as slots')->pluck('slots', 'position_id');
        $default = app(CoreSettings::class)->int('workforce.default_seat_capacity');

        return fn (string $id): int => (int) ($approved[$id] ?? 0) > 0 ? (int) $approved[$id] : $default;
    }

    /**
     * The lifecycle panel (ADR 0010): the status and reason, who created and
     * approved it, the masters it uses, its seats against its holders, the
     * steps this reader may take now, and the position's history.
     *
     * @return array<string, mixed>
     */
    private function lifecycle(User $user, Position $position): array
    {
        $inScope = $this->scope->allows($user, (string) $position->organization_id);
        $names = User::query()->whereIn('id', array_filter([$position->created_by, $position->approved_by]))->pluck('name', 'id');
        $history = $position->histories()->limit(50)->get();
        $actors = User::query()->whereIn('id', $history->pluck('actor_id')->filter()->unique())->pluck('name', 'id');
        $master = fn ($value): ?array => $value?->only(['id', 'code', 'name_en', 'name_am']);
        $seats = app(SeatCapacity::class)->capacity((string) $position->id);

        return [
            'status' => $position->lifecycle_status,
            'reason' => $position->status_reason,
            'created_by' => $names->get($position->created_by),
            'approved_by' => $names->get($position->approved_by),
            'approved_at' => $position->approved_at?->toIso8601String(),
            'masters' => [
                'grade' => $master($position->grade), 'level' => $master($position->level), 'occupation' => $master($position->occupation),
                'profession' => $master($position->profession), 'employment_type' => $master($position->employmentType), 'category' => $master($position->category),
            ],
            'occupancy' => ['seats' => $seats, 'holders' => (int) $position->holders_count, 'vacant' => max(0, $seats - (int) $position->holders_count)],
            'steps' => $position->trashed() || ! $inScope ? [] : collect(ChangePositionLifecycle::allowedFrom((string) $position->lifecycle_status))
                ->filter(fn (string $step): bool => $user->can(ChangePositionLifecycle::permissionFor($step)))
                ->map(fn (string $step): array => ['step' => $step, 'needs_reason' => ChangePositionLifecycle::needsReason($step)])->values(),
            'step_url' => route('positions.lifecycle', $position),
            'history' => $history->map(fn ($row): array => [
                'id' => $row->id, 'change_type' => $row->change_type, 'changes' => $row->changes, 'reason' => $row->reason,
                'effective_from' => $row->effective_from?->toDateString(), 'actor' => $actors->get($row->actor_id), 'at' => $row->created_at?->toIso8601String(),
            ])->values(),
        ];
    }

    /** @return list<array{id: string, isco_code: string, name_en: string, name_am: string|null}> */
    private function occupationOptions(): array
    {
        return Occupation::query()->where('is_active', true)->orderBy('sort_order')->orderBy('code')->get(['id', 'code', 'name_en', 'name_am'])
            ->map(fn (Occupation $o): array => ['id' => $o->id, 'isco_code' => $o->code, 'name_en' => $o->name_en, 'name_am' => $o->name_am])->all();
    }

    /** The other job masters for the position form (ADR 0010): active values only. */
    private function masterOptions(): array
    {
        $options = [];
        foreach (['levels' => 'job_level_id', 'professions' => 'profession_id', 'employment_types' => 'employment_type_id', 'categories' => 'position_category_id'] as $kind => $column) {
            $class = ManageJobMasters::KINDS[$kind][0];
            $options[] = ['kind' => $kind, 'field' => $column, 'options' => $class::query()->where('is_active', true)->orderBy('sort_order')->orderBy('code')->get(['id', 'code', 'name_en', 'name_am'])->all()];
        }

        return $options;
    }

    /** @return list<string> */
    private function gradeLevels(): array
    {
        return JobGrade::query()->where('is_active', true)->orderBy('sort_order')->orderBy('code')->pluck('code')->all();
    }

    private function organizationOptions(User $user): Collection
    {
        $ids = $this->scope->organizationIds($user);

        return Organization::query()->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))->orderBy('name_en')->get(['id', 'name_en', 'name_am']);
    }

    /** @return array<string, mixed> */
    private function establishmentPayload(PositionEstablishment $establishment): array
    {
        return [
            'id' => $establishment->id,
            'establishment_number' => $establishment->establishment_number,
            'status' => $establishment->status,
            'approved_slots' => $establishment->approved_slots,
            'effective_from' => $establishment->effective_from?->toDateString(),
            'effective_to' => $establishment->effective_to?->toDateString(),
            'approval_reference' => $establishment->approval_reference,
            'notes' => $establishment->notes,
            'approved_at' => $establishment->approved_at?->toIso8601String(),
            'organization' => $establishment->organization?->only(['id', 'name_en', 'name_am']),
            'position' => $establishment->position?->only(['id', 'title_en', 'title_am', 'job_position_code']),
            'occupation' => null,
            'approved_by' => $establishment->approver?->only(['name']),
        ];
    }

    /** @return array{update: bool, approve: bool, archive: bool} */
    private function establishmentCan(User $user, PositionEstablishment $establishment): array
    {
        $inScope = $this->scope->allows($user, (string) $establishment->organization_id);
        $live = in_array($establishment->status, ['draft', 'approved'], true);

        return [
            'update' => $inScope && $live && $user->can('position-establishments.update'),
            'approve' => $inScope && $establishment->status === 'draft' && $user->can('position-establishments.approve'),
            'archive' => $inScope && $establishment->status === PositionEstablishment::APPROVED && $user->can('position-establishments.archive'),
        ];
    }

    /**
     * The organization list as a tree of the published hierarchy.
     *
     * @param  Collection<int, Organization>  $organizations
     * @return list<array<string, mixed>>
     */
    private function organizationTree(Collection $organizations): array
    {
        $version = HierarchyVersion::current();
        $byId = $organizations->keyBy('id');
        $node = fn (Organization $o, int $depth, array $children): array => [
            'id' => $o->id, 'code' => $o->code, 'name_en' => $o->name_en, 'name_am' => $o->name_am, 'status' => $o->status,
            'logo_url' => null, 'has_logo' => false, 'organization_units_count' => $o->units_count,
            'type' => $o->type?->only(['id', 'code', 'name_en', 'name_am']), 'depth' => $depth, 'children' => $children,
        ];
        if ($version === null) {
            return $organizations->map(fn (Organization $o): array => $node($o, 0, []))->values()->all();
        }
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

            return $node($byId->get($id), $depth, array_values(array_filter(array_map(fn (string $c): ?array => $build($c, $depth + 1, [...$path, $id]), $childrenOf[$id] ?? []))));
        };

        return $organizations->reject(fn (Organization $o): bool => isset($childIds[$o->id]))->map(fn (Organization $o): ?array => $build((string) $o->id, 0, []))->filter()->values()->all();
    }

    /**
     * EUISIS ScopedPositionStructureService: every organization in scope, with units and positions for the expanded one.
     *
     * @param  Collection<int, Organization>  $organizations
     * @return list<array<string, mixed>>
     */
    private function structure(Collection $organizations, ?string $expanded): array
    {
        if ($expanded === null && $organizations->count() === 1) {
            $expanded = (string) $organizations->first()->id;
        }
        $units = $expanded === null ? collect() : OrganizationUnit::query()->where('organization_id', $expanded)->orderBy('sort_order')->orderBy('name_en')
            ->get(['id', 'organization_id', 'parent_unit_id', 'code', 'name_en', 'name_am', 'status']);
        $positions = $expanded === null ? collect() : Position::query()->where('organization_id', $expanded)->orderBy('title_en')
            ->get(['id', 'organization_id', 'organization_unit_id', 'job_position_code', 'old_code', 'title_en', 'title_am', 'bpr_name', 'is_active']);
        $occupied = EmployeeAssignment::query()->whereIn('position_id', $positions->pluck('id'))->where('is_current', true)
            ->where('assignment_status', EmployeeAssignment::ACTIVE)->pluck('position_id')->flip();
        $positionsByUnit = $positions->groupBy(fn (Position $p): string => (string) ($p->organization_unit_id ?? ''));
        $unitsByParent = $units->groupBy(fn (OrganizationUnit $u): string => (string) ($u->parent_unit_id ?? ''));
        $build = function (string $organizationId, ?string $parentId) use (&$build, $unitsByParent, $positionsByUnit, $occupied): array {
            return $unitsByParent->get((string) ($parentId ?? ''), collect())->where('organization_id', $organizationId)->map(fn (OrganizationUnit $unit): array => [
                'id' => $unit->id, 'code' => $unit->code, 'name_en' => $unit->name_en, 'name_am' => $unit->name_am,
                'parent_unit_id' => $unit->parent_unit_id, 'status' => $unit->status,
                'positions' => $positionsByUnit->get((string) $unit->id, collect())->map(fn (Position $p): array => [
                    'id' => $p->id, 'code' => $p->job_position_code, 'old_code' => $p->old_code, 'standard_name' => $p->title_en, 'standard_name_am' => $p->title_am,
                    'bpr_name' => $p->bpr_name, 'organization_unit_id' => $p->organization_unit_id, 'status' => $p->is_active ? 'active' : 'inactive',
                    'occupancy_status' => $occupied->has($p->id) ? 'occupied' : 'vacant',
                ])->values()->all(),
                'children' => $build($organizationId, (string) $unit->id),
            ])->values()->all();
        };

        return $organizations->map(fn (Organization $o): array => [
            'id' => $o->id, 'code' => $o->code, 'name_en' => $o->name_en, 'name_am' => $o->name_am, 'status' => $o->status,
            'units' => (string) $o->id === $expanded ? $build((string) $o->id, null) : [],
        ])->values()->all();
    }
}

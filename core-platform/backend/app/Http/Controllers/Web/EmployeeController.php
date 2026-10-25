<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\EmployeeVerification;
use App\Actions\RegisterEmployee;
use App\Actions\UpdateEmployee;
use App\Http\Controllers\Controller;
use App\Models\CoreAuditLog;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\HierarchyVersion;
use App\Models\IdCard;
use App\Models\Organization;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Models\User;
use App\Services\CoreSettings;
use App\Services\EvidenceView;
use App\Services\OrganizationScope;
use App\Services\SeatCapacity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * EUISIS Employees/* over the Core tables. Registration and changes go through
 * RegisterEmployee and UpdateEmployee (one placement, seat capacity, the
 * employment-status rules of ADR 0002).
 */
class EmployeeController extends Controller
{
    private const EMPLOYMENT_TYPES = ['permanent', 'contract', 'temporary', 'probation'];

    /** The status filter: the verified-employee stage first (ADR 0008), then the employment statuses. */
    private const STATUS_OPTIONS = ['draft', 'submitted', 'verified', 'active', 'suspended', 'retired', 'terminated', 'deceased', 'transferred'];

    /** Session key: the person the registration form last warned about as a possible duplicate. */
    private const DUPLICATE_WARNING = 'core.employee-duplicate-warning';

    public function __construct(private readonly OrganizationScope $scope, private readonly SeatCapacity $seats) {}

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $ids = $this->scope->organizationIds($user);
        $organizations = Organization::query()->orderBy('name_en')->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))->get(['id', 'code', 'name_en', 'name_am', 'status']);
        $organizationId = $request->string('organization_id')->toString() ?: ($ids !== null ? $organizations->first()?->id : null);
        $unitId = $request->string('organization_unit_id')->toString() ?: null;
        $positionId = $request->string('position_id')->toString() ?: null;
        abort_if($organizationId !== null && ! $this->scope->allows($user, (string) $organizationId), 403);
        $selectedOrganization = $organizationId === null ? null : Organization::query()->with('type:id,name_en,name_am,code')->withCount('units')
            ->find($organizationId, ['id', 'organization_type_id', 'code', 'name_en', 'name_am', 'status', 'logo_path', 'effective_from']);
        $selectedPosition = $positionId === null ? null : $this->scope->apply(Position::query()->where('is_active', true)->whereKey($positionId), $user)
            ->first(['id', 'job_position_code', 'title_en', 'title_am', 'organization_id', 'organization_unit_id']);
        abort_if($positionId !== null && $selectedPosition === null, 403);

        $search = trim($request->string('search')->toString());
        $status = $request->string('status')->toString();
        $employmentType = $request->string('employment_type')->toString();
        $sort = in_array($request->string('sort')->toString(), ['full_name', 'employee_number', 'employment_type', 'status', 'created_at'], true) ? $request->string('sort')->toString() : 'full_name';
        $direction = $request->string('direction')->toString() === 'desc' ? 'desc' : 'asc';

        $page = Employee::query()->with(['currentAssignment.organization', 'currentAssignment.unit', 'currentAssignment.position'])
            ->withExists('activeIdCard as has_active_card')
            ->when($ids !== null, fn ($q) => $q->whereHas('currentAssignment', fn ($a) => $a->whereIn('organization_id', $ids)))
            ->when($organizationId !== null, fn ($q) => $q->whereHas('currentAssignment', fn ($a) => $a->where('organization_id', $organizationId)))
            ->when($unitId !== null, fn ($q) => $q->whereHas('currentAssignment', fn ($a) => $a->where('organization_unit_id', $unitId)))
            ->when($selectedPosition !== null, fn ($q) => $q->whereHas('currentAssignment', fn ($a) => $a->where('position_id', $selectedPosition->id)))
            ->when($search !== '', fn ($q) => $q->where(fn ($inner) => $inner->where('employee_number', 'like', $search.'%')->orWhere('full_name', 'like', "%{$search}%")
                ->orWhere('name_en', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%")))
            ->when(in_array($employmentType, self::EMPLOYMENT_TYPES, true), fn ($q) => $q->where('employment_type', $employmentType))
            ->when($status !== '', fn ($q) => $q->where('status', $status))
            ->orderBy($sort, $direction)->orderBy('id')
            ->paginate(50)->withQueryString();

        return Inertia::render('Employees/Index', [
            'isOrganizationScoped' => $ids !== null,
            'selectedOrganization' => $selectedOrganization,
            'selectedPosition' => $selectedPosition === null ? null : $selectedPosition->only(['id', 'job_position_code', 'title_en', 'title_am', 'organization_unit_id'])
                + ['occupancy_status' => $this->seats->hasFreeSeat((string) $selectedPosition->id) ? 'vacant' : 'occupied'],
            'organizations' => $organizations->map(fn (Organization $o): array => $o->only(['id', 'code', 'name_en', 'name_am']))->values(),
            'organizationUnits' => $organizationId === null ? [] : OrganizationUnit::query()->where('status', 'active')->where('organization_id', $organizationId)->orderBy('name_en')
                ->get(['id', 'organization_id', 'code', 'name_en', 'name_am']),
            'positions' => $organizationId === null ? [] : Position::query()->where('is_active', true)->where('organization_id', $organizationId)
                ->when($unitId !== null, fn ($q) => $q->where('organization_unit_id', $unitId))->orderBy('title_en')->limit(500)
                ->get(['id', 'organization_id', 'organization_unit_id', 'job_position_code', 'title_en', 'title_am']),
            'showOrganizationColumn' => $organizations->count() > 1,
            'employees' => $page->getCollection()->map(fn (Employee $employee): array => $this->row($employee) + ['has_active_card' => (bool) $employee->has_active_card])->values(),
            'employees_pagination' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'page_limit_reached' => false],
            'filters' => [
                'search' => $search, 'status' => $status, 'organization_id' => $organizationId ?? '', 'organization_unit_id' => $unitId ?? '',
                'position_id' => $positionId ?? '', 'employment_type' => in_array($employmentType, self::EMPLOYMENT_TYPES, true) ? $employmentType : '',
            ],
            'sort' => ['column' => $sort, 'direction' => $direction],
            'statusOptions' => self::STATUS_OPTIONS,
            'can' => ['create' => $user->can('employees.create'), 'update' => $user->can('employees.manage')],
        ]);
    }

    public function create(Request $request): Response|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $organizationId = $request->string('organization_id')->toString() ?: null;
        $unitId = $request->string('organization_unit_id')->toString() ?: null;
        $positionId = $request->string('position_id')->toString() ?: null;
        abort_if($organizationId !== null && ! $this->scope->allows($user, $organizationId), 403);

        $position = $positionId === null ? null : $this->scope->apply(Position::query()->where('is_active', true)->whereKey($positionId), $user)->first();
        if ($positionId !== null && ($position === null || ! $this->seats->hasFreeSeat($positionId))) {
            return back()->with('flash', ['message' => __('validation.position_already_occupied'), 'type' => 'error']);
        }
        if ($position !== null) {
            $organizationId ??= $position->organization_id;
            $unitId ??= $position->organization_unit_id;
        }
        $organizations = $this->scope->apply(Organization::query()->where('status', 'active')->orderBy('name_en'), $user, 'id')->get(['id', 'name_en', 'name_am']);
        if ($organizationId === null && $organizations->count() === 1) {
            $organizationId = $organizations->first()->id;
        }
        $optionsFor = $organizationId ?? $organizations->first()?->id;

        return Inertia::render('Employees/Create', [
            'organizations' => $organizations,
            'organizationUnits' => OrganizationUnit::query()->where('status', 'active')->where('organization_id', $optionsFor)->orderBy('name_en')->get(['id', 'organization_id', 'name_en', 'name_am', 'code']),
            'hierarchyVersions' => HierarchyVersion::query()->orderByDesc('effective_from')->get(['id', 'version_name', 'status']),
            'positions' => $this->withFreeSeat(Position::query()->where('is_active', true)->where('organization_id', $optionsFor))->orderBy('title_en')->limit(500)
                ->get(['id', 'job_position_code', 'title_en', 'title_am', 'organization_id', 'organization_unit_id']),
            'selectedOrganizationId' => $organizationId,
            'selectedOrganizationUnitId' => $unitId,
            'selectedPositionId' => $positionId,
            'organizationLocked' => $organizationId !== null && $organizations->count() === 1,
            'placementContext' => $position === null ? null : [
                'organization' => Organization::query()->find($organizationId, ['id', 'code', 'name_en', 'name_am'])?->only(['id', 'code', 'name_en', 'name_am']),
                'organization_unit' => $unitId === null ? null : OrganizationUnit::query()->find($unitId, ['id', 'code', 'name_en', 'name_am'])?->only(['id', 'code', 'name_en', 'name_am']),
                'position' => ['id' => $position->id, 'code' => $position->job_position_code, 'name_en' => $position->title_en, 'name_am' => $position->title_am],
            ],
        ]);
    }

    public function store(Request $request, RegisterEmployee $register, UpdateEmployee $update): RedirectResponse
    {
        $data = $request->validate([
            'employee_number' => ['nullable', 'string', 'max:64'],
            'position_id' => ['required', 'uuid', Rule::exists('positions', 'id')],
            'effective_from' => ['nullable', 'date'],
            'reason' => ['nullable', 'string', 'max:1000'],
            ...$this->personRules(),
        ]);
        $reason = trim((string) ($data['reason'] ?? ''));
        unset($data['reason']);
        // The person as typed: a confirmation given after the warning holds only for the same person.
        $person = sha1(implode('|', array_map(fn (string $key): string => mb_strtolower(trim((string) ($data[$key] ?? ''))), ['first_name', 'middle_name', 'last_name', 'date_of_birth', 'phone', 'email'])));
        $warned = $request->session()->get(self::DUPLICATE_WARNING) === $person;
        try {
            // A blank number is generated; the national id is kept only as its keyed hash.
            $employee = $register->execute([
                ...$data,
                'effective_from' => $data['effective_from'] ?? now()->toDateString(),
                // RegisterEmployee's confirmation step on the EUISIS form: after the warning, the reason field says why this is a different person.
                'confirm_not_duplicate' => $warned && mb_strlen($reason) >= 5,
                'duplicate_reason' => $warned ? $reason : null,
            ], $request->user());
        } catch (HttpResponseException $exception) {
            // 409 POSSIBLE_DUPLICATE: warn once, naming the matches the reader may see.
            $request->session()->put(self::DUPLICATE_WARNING, $person);
            $matches = collect($exception->getResponse()->getData(true)['matches'] ?? [])->pluck('employee_number')->filter()->implode(', ');
            throw ValidationException::withMessages(['reason' => __('This person may already be registered').($matches !== '' ? " ({$matches})" : '')
                .'. '.__('If this is a different person, write here why, then save again.')]);
        }
        $request->session()->forget(self::DUPLICATE_WARNING);
        $details = array_filter(array_intersect_key($data, array_flip(['name_en', 'nationality', 'employment_type', 'address', 'emergency_contact_name', 'emergency_contact_phone'])), fn ($v) => $v !== null);
        if ($details !== []) {
            $update->details($employee, $details, $request->user());
        }

        return to_route('employees.show', $employee)->with('flash', ['message' => __('employees.created_successfully'), 'type' => 'success']);
    }

    public function show(Request $request, Employee $employee, EmployeeVerification $verification): Response
    {
        $this->authorizeScope($request->user(), $employee);
        $employee->load(['currentAssignment.organization', 'currentAssignment.position', 'assignments.organization', 'assignments.position', 'activeIdCard']);
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('Employees/Show', [
            'employee' => ['photo_url' => app(EvidenceView::class)->photoUrl($employee, $user)] + $this->detail($employee),
            'organizations' => $this->scope->apply(Organization::query()->orderBy('name_en'), $user, 'id')->get(['id', 'name_en']),
            'qrCodes' => ['canViewIdQr' => false, 'canManageFeedbackQr' => false, 'idCard' => null, 'feedback' => null],
            'recentActivity' => $user->can('audit.view') ? $this->activity($employee) : null,
            'workflow' => $this->workflow($employee, $user, $verification),
            'evidence' => app(EvidenceView::class)->for($employee, $user),
        ]);
    }

    /** One step of the verified-employee stage (submit, verify, return, approve); EmployeeVerification decides. */
    public function workflowStep(Request $request, Employee $employee, string $step, EmployeeVerification $verification): RedirectResponse
    {
        $checks = array_keys($verification->checklist($employee));
        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:1000'],
            'reason' => [$step === 'return' ? 'required' : 'nullable', 'string', 'min:5', 'max:1000'],
            'checks' => ['nullable', 'array'],
            'checks.*' => ['boolean'],
        ]);
        $user = $request->user();
        match ($step) {
            'submit' => $verification->submit($employee, $user, $data['notes'] ?? null),
            'verify' => $verification->verify($employee, $user, array_intersect_key(array_map('boolval', $data['checks'] ?? []), array_flip($checks)), $data['notes'] ?? null),
            'return' => $verification->returnForCorrection($employee, $user, (string) $data['reason']),
            'approve' => $verification->approve($employee, $user, $data['notes'] ?? null),
        };

        return to_route('employees.show', $employee)->with('flash', ['message' => __('employees.workflow.done.'.$step), 'type' => 'success']);
    }

    public function edit(Request $request, Employee $employee): Response
    {
        $this->authorizeScope($request->user(), $employee);
        $employee->load(['currentAssignment.organization', 'currentAssignment.position']);

        return Inertia::render('Employees/Edit', ['employee' => $this->detail($employee)]);
    }

    public function update(Request $request, Employee $employee, UpdateEmployee $update): RedirectResponse
    {
        $data = $request->validate([...$this->personRules(), 'status' => ['sometimes', Rule::in(UpdateEmployee::STATUSES)], 'status_reason' => ['nullable', 'string', 'max:1000']]);
        $status = $data['status'] ?? null;
        unset($data['status'], $data['status_reason'], $data['national_id']);
        $update->details($employee, $data, $request->user());
        if ($status !== null && $status !== $employee->fresh()->status) {
            $update->status($employee, $status, $request->string('status_reason')->toString() ?: __('Employment status changed on the employee form.'), $request->user());
        }

        return to_route('employees.show', $employee)->with('flash', ['message' => __('employees.updated_successfully'), 'type' => 'success']);
    }

    /** @return array<string, list<mixed>> */
    private function personRules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'national_id' => ['nullable', 'string', 'max:64'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'gender' => ['nullable', 'string', 'max:32'],
            'address' => ['nullable', 'string', 'max:1000'],
            'nationality' => ['nullable', 'string', 'max:100'],
            'employment_type' => ['nullable', Rule::in(self::EMPLOYMENT_TYPES)],
            'emergency_contact_name' => ['nullable', 'string', 'max:255'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:50'],
        ];
    }

    /**
     * The verified-employee stage for the EUISIS employee page (its optional
     * workflow card): who did each step and when, the checklist, the notes, and
     * only the actions this reader may take now. EmployeeVerification decides again.
     *
     * @return array<string, mixed>
     */
    private function workflow(Employee $employee, User $user, EmployeeVerification $verification): array
    {
        $actors = User::query()->whereIn('id', array_filter([$employee->registered_by, $employee->submitted_by, $employee->verified_by, $employee->approved_by]))->pluck('name', 'id');
        $step = fn (string $key, mixed $by, mixed $at): array => ['key' => $key, 'by' => $by === null ? null : $actors->get($by), 'at' => $at?->toIso8601String()];
        $own = in_array((int) $user->id, array_map('intval', array_filter([$employee->registered_by, $employee->submitted_by, $employee->verified_by])), true);
        $checked = $employee->verification_checklist ?? [];

        return [
            'status' => $employee->status,
            'verification_status' => $employee->verification_status,
            'steps' => [
                $step('registered', $employee->registered_by, $employee->created_at),
                $step('submitted', $employee->submitted_by, $employee->submitted_at),
                $step('verified', $employee->verified_by, $employee->verified_at),
                $step('approved', $employee->approved_by, $employee->approved_at),
            ],
            'notes' => $employee->verification_notes,
            'checklist' => array_map(fn (string $key, bool $required): array => ['key' => $key, 'required' => $required, 'checked' => (bool) ($checked[$key] ?? false)],
                array_keys($verification->checklist($employee)), $verification->checklist($employee)),
            'can' => [
                'submit' => $employee->status === Employee::DRAFT && $user->can('employees.submit'),
                'verify' => $employee->status === Employee::SUBMITTED && $user->can('employees.verify') && (int) $employee->submitted_by !== (int) $user->id,
                'return' => in_array($employee->status, [Employee::SUBMITTED, Employee::VERIFIED], true) && $user->can('employees.return'),
                'approve' => $employee->status === Employee::VERIFIED && $user->can('employees.approve') && ! $own,
            ],
            'urls' => array_combine(['submit', 'verify', 'return', 'approve'], array_map(
                fn (string $step): string => route('employees.workflow', ['employee' => $employee->id, 'step' => $step]), ['submit', 'verify', 'return', 'approve'])),
        ];
    }

    private function authorizeScope(User $user, Employee $employee): void
    {
        $organizationId = $employee->currentAssignment?->organization_id ?? $employee->assignments()->latest('effective_from')->value('organization_id');
        abort_unless($user->isCityWide() || ($organizationId !== null && $this->scope->allows($user, (string) $organizationId)), 403);
    }

    /** Positions with a free seat (SeatCapacity: approved slots, or the Workforce default). */
    private function withFreeSeat(Builder $positions): Builder
    {
        $default = app(CoreSettings::class)->int('workforce.default_seat_capacity');
        $today = now()->toDateString();

        return $positions->whereRaw(
            '(select count(*) from employee_assignments ea where ea.position_id = positions.id and ea.is_current = ? and ea.assignment_status = ?)'
            .' < coalesce(nullif((select sum(pe.approved_slots) from position_establishments pe where pe.position_id = positions.id and pe.status = ? and pe.deleted_at is null'
            .' and pe.effective_from <= ? and (pe.effective_to is null or pe.effective_to >= ?)), 0), ?)',
            [true, EmployeeAssignment::ACTIVE, 'approved', $today, $today, $default],
        );
    }

    /** @return array<string, mixed> */
    private function row(Employee $employee): array
    {
        $assignment = $employee->currentAssignment;

        return [
            'id' => $employee->id,
            'employee_number' => $employee->employee_number,
            'full_name' => $employee->full_name,
            'first_name' => $employee->first_name,
            'last_name' => $employee->last_name,
            'name_en' => $employee->name_en,
            'phone' => $employee->phone,
            'email' => $employee->email,
            'photo_url' => null,
            'status' => $employee->status,
            'employment_type' => $employee->employment_type,
            'current_assignment' => $assignment === null ? null : [
                'id' => $assignment->id,
                'assignment_status' => $assignment->assignment_status,
                'effective_from' => $assignment->effective_from?->toDateString(),
                'effective_to' => $assignment->effective_to?->toDateString(),
                'organization' => $assignment->organization?->only(['id', 'name_en', 'name_am']),
                'organization_unit' => $assignment->relationLoaded('unit') ? $assignment->unit?->only(['id', 'code', 'name_en', 'name_am']) : null,
                'position' => $assignment->position?->only(['id', 'job_position_code', 'title_en', 'title_am']),
            ],
        ];
    }

    /**
     * EUISIS EmployeeDetailResource. Contact details show to Core readers as in EUISIS (employees.view); the national id
     * needs employees.viewPii there, which no Core role holds, so it stays hidden (ADR 0006 addendum).
     *
     * @return array<string, mixed>
     */
    private function detail(Employee $employee): array
    {
        return $this->row($employee) + [
            'middle_name' => $employee->middle_name,
            'national_id' => null,
            'photo_path' => null,
            'date_of_birth' => $employee->date_of_birth?->toDateString(),
            'gender' => $employee->gender,
            'nationality' => $employee->nationality,
            'employment_type_label' => $employee->employment_type === null ? null : ucfirst((string) $employee->employment_type),
            'address' => $employee->address,
            'emergency_contact_name' => $employee->emergency_contact_name,
            'emergency_contact_phone' => $employee->emergency_contact_phone,
            'data_quality_score' => $employee->data_quality_score === null ? null : (float) $employee->data_quality_score,
            'assignments' => $employee->relationLoaded('assignments') ? $employee->assignments->sortByDesc('effective_from')->map(fn (EmployeeAssignment $a): array => [
                'id' => $a->id,
                'assignment_status' => $a->assignment_status,
                'effective_from' => $a->effective_from?->toDateString(),
                'effective_to' => $a->effective_to?->toDateString(),
                'reason' => $a->reason,
                'organization' => $a->organization?->only(['id', 'name_en', 'name_am']),
                'position' => $a->position?->only(['id', 'title_en', 'title_am']),
            ])->values()->all() : [],
            'duplicate_flags' => [],
            'documents' => [],
            'transfers' => [],
        ];
    }

    /** @return list<array{event_type: string, created_at: string, actor: string|null}> */
    private function activity(Employee $employee): array
    {
        $cardIds = IdCard::query()->where('employee_id', $employee->id)->pluck('id')->all();
        $logs = CoreAuditLog::query()->where(fn ($q) => $q->where(fn ($e) => $e->where('subject_type', 'Employee')->where('subject_id', $employee->id))
            ->orWhere(fn ($a) => $a->where('subject_type', 'EmployeeAssignment')->whereIn('subject_id', $employee->assignments->pluck('id')))
            ->orWhere(fn ($c) => $c->where('subject_type', 'IdCard')->whereIn('subject_id', $cardIds)))
            ->latest('created_at')->limit(15)->get(['id', 'action', 'created_at', 'actor_user_id']);
        $actors = User::query()->whereIn('id', $logs->pluck('actor_user_id')->filter()->unique())->pluck('name', 'id');

        return $logs->map(fn (CoreAuditLog $log): array => [
            'event_type' => $log->action,
            'created_at' => $log->created_at?->toIso8601String() ?? '',
            'actor' => $log->actor_user_id !== null ? ($actors[$log->actor_user_id] ?? null) : null,
        ])->all();
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Employee;

use App\Enums\AssignmentStatus;
use App\Enums\FieldWorkDestinationType;
use App\Enums\FieldWorkLocationValidation;
use App\Enums\FieldWorkStatus;
use App\Enums\FieldWorkSupervisorResolution;
use App\Http\Controllers\Controller;
use App\Http\Requests\FieldWork\CompleteFieldWorkRequest;
use App\Http\Requests\FieldWork\RecordFieldWorkLocationRequest;
use App\Http\Requests\FieldWork\SaveFieldWorkRequest;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\FieldWorkRequest;
use App\Models\FieldWorkType;
use App\Models\Organization;
use App\Models\OrganizationUnit;
use App\Services\FieldWork\FieldWorkPresenter;
use App\Services\FieldWork\FieldWorkService;
use App\Services\FieldWork\FieldWorkSettings;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * My Portal > Field Work: the signed-in employee's own requests and the team
 * field work they take part in.
 *
 * No route here accepts an employee id. The requester is always the user's
 * linked employee record; request ids are re-authorised against the record
 * by FieldWorkRequestPolicy, so another employee's field work is a 403.
 */
class FieldWorkController extends Controller
{
    private const ACTIVE_STATUSES = [
        FieldWorkStatus::Draft,
        FieldWorkStatus::PendingSupervisorApproval,
        FieldWorkStatus::ReturnedForCorrection,
        FieldWorkStatus::Approved,
        FieldWorkStatus::InField,
    ];

    public function __construct(
        private readonly FieldWorkService $service,
        private readonly FieldWorkPresenter $presenter,
        private readonly FieldWorkSettings $settings,
    ) {}

    public function index(Request $request): Response
    {
        return $this->list($request, history: false);
    }

    public function history(Request $request): Response
    {
        return $this->list($request, history: true);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()->can('field_work.create_own'), 403);

        return Inertia::render('Employee/FieldWork/Form', [
            ...$this->formProps($request),
            'fieldWork' => null,
            'values' => null,
        ]);
    }

    public function store(SaveFieldWorkRequest $request): RedirectResponse
    {
        abort_unless($request->user()->can('field_work.create_own'), 403);
        abort_if($request->submitting() && ! $request->user()->can('field_work.submit_own'), 403);

        $fieldWork = $this->service->create($request->user(), $request->payload(), $request->submitting());

        return $this->afterSave(redirect()->route('employee.field-work.show', $fieldWork), $fieldWork, $request->submitting());
    }

    public function show(Request $request, FieldWorkRequest $fieldWorkRequest): Response
    {
        $user = $request->user();
        abort_unless($this->ownsOrParticipates($request, $fieldWorkRequest), 403);

        return Inertia::render('Employee/FieldWork/Show', [
            'fieldWork' => $this->presenter->detail($fieldWorkRequest, $user),
            'isRequester' => $fieldWorkRequest->requester_employee_id === $user->employee?->id,
            'can' => [
                'edit' => $user->can('update', $fieldWorkRequest),
                'submit' => $user->can('submit', $fieldWorkRequest),
                'cancel' => $user->can('cancel', $fieldWorkRequest),
                'checkIn' => $user->can('checkIn', $fieldWorkRequest),
                'checkOut' => $user->can('checkOut', $fieldWorkRequest),
                'complete' => $user->can('complete', $fieldWorkRequest),
            ],
            'now' => $this->settings->local(now()),
        ]);
    }

    public function edit(Request $request, FieldWorkRequest $fieldWorkRequest): Response
    {
        $this->authorize('update', $fieldWorkRequest);

        return Inertia::render('Employee/FieldWork/Form', [
            ...$this->formProps($request),
            'fieldWork' => ['id' => $fieldWorkRequest->id, 'reference_number' => $fieldWorkRequest->reference_number, 'status' => $fieldWorkRequest->status->value, 'decision_reason' => $fieldWorkRequest->decision_reason],
            'values' => $this->presenter->formValues($fieldWorkRequest),
        ]);
    }

    public function update(SaveFieldWorkRequest $request, FieldWorkRequest $fieldWorkRequest): RedirectResponse
    {
        $this->authorize('update', $fieldWorkRequest);
        abort_if($request->submitting() && ! $request->user()->can('field_work.submit_own'), 403);

        $fieldWork = $this->service->update($request->user(), $fieldWorkRequest, $request->payload(), $request->submitting());

        return $this->afterSave(redirect()->route('employee.field-work.show', $fieldWork), $fieldWork, $request->submitting());
    }

    public function submit(Request $request, FieldWorkRequest $fieldWorkRequest): RedirectResponse
    {
        $this->authorize('submit', $fieldWorkRequest);
        $fieldWork = $this->service->submit($request->user(), $fieldWorkRequest);

        return $this->afterSave(back(), $fieldWork, true);
    }

    public function cancel(Request $request, FieldWorkRequest $fieldWorkRequest): RedirectResponse
    {
        $this->authorize('cancel', $fieldWorkRequest);
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:2000']]);
        $this->service->cancel($request->user(), $fieldWorkRequest, $validated['reason'] ?? null);

        return back()->with('success', __('field-work.flash.cancelled'));
    }

    public function checkIn(RecordFieldWorkLocationRequest $request, FieldWorkRequest $fieldWorkRequest): RedirectResponse
    {
        abort_unless($this->ownsOrParticipates($request, $fieldWorkRequest) && $request->user()->can('field_work.check_in'), 403);
        [$event, $created] = $this->service->checkIn($request->user(), $fieldWorkRequest, $request->gps());

        return back()->with($this->locationFlashType($event->validation_status, $created), __($created ? 'field-work.flash.checked_in' : 'field-work.flash.already_checked_in'));
    }

    public function checkOut(RecordFieldWorkLocationRequest $request, FieldWorkRequest $fieldWorkRequest): RedirectResponse
    {
        abort_unless($this->ownsOrParticipates($request, $fieldWorkRequest) && $request->user()->can('field_work.check_out'), 403);
        [$event, $created] = $this->service->checkOut($request->user(), $fieldWorkRequest, $request->gps());

        return back()->with($this->locationFlashType($event->validation_status, $created), __($created ? 'field-work.flash.checked_out' : 'field-work.flash.already_checked_out'));
    }

    public function complete(CompleteFieldWorkRequest $request, FieldWorkRequest $fieldWorkRequest): RedirectResponse
    {
        $this->authorize('complete', $fieldWorkRequest);
        $this->service->complete($request->user(), $fieldWorkRequest, $request->payload());

        return back()->with('success', __('field-work.flash.completed'));
    }

    /**
     * Colleagues the requester may add to team field work: active employees
     * currently placed in the requester's own organization. Server-side
     * search, at most 20 rows, never a full employee list.
     */
    public function colleagues(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('field_work.create_team'), 403);
        $employee = $request->user()->employee;
        $organizationId = $employee?->currentAssignment?->organization_id;
        $term = trim((string) $request->query('q', ''));

        if ($employee === null || $organizationId === null || mb_strlen($term) < 2) {
            return response()->json(['data' => []]);
        }

        $like = ci_like_operator();
        $pattern = '%'.addcslashes(mb_substr($term, 0, 60), '%_\\').'%';

        $rows = Employee::query()
            ->where('status', 'active')
            ->whereKeyNot($employee->id)
            ->whereIn('current_assignment_id', EmployeeAssignment::query()
                ->where('organization_id', $organizationId)
                ->where('is_current', true)
                ->where('assignment_status', '!=', AssignmentStatus::PendingTransfer->value)
                ->select('id'))
            ->where(fn (Builder $q) => $q->where('employee_number', $like, $pattern)->orWhere('full_name', $like, $pattern)->orWhere('name_en', $like, $pattern))
            ->orderBy('full_name')
            ->limit(20)
            ->get(['id', 'employee_number', 'full_name', 'name_en']);

        return response()->json(['data' => $rows->map(fn (Employee $e): ?array => $this->presenter->employee($e))->values()]);
    }

    /** Registered destination organizations, searched server-side (master data, not HR data). */
    public function destinationOrganizations(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('field_work.create_own'), 403);
        $term = trim((string) $request->query('q', ''));
        if (mb_strlen($term) < 2) {
            return response()->json(['data' => []]);
        }

        $like = ci_like_operator();
        $pattern = '%'.addcslashes(mb_substr($term, 0, 60), '%_\\').'%';

        $rows = Organization::query()
            ->where('status', 'active')
            ->where(fn (Builder $q) => $q->where('name_en', $like, $pattern)->orWhere('name_am', $like, $pattern)->orWhere('code', $like, $pattern))
            ->orderBy('name_en')
            ->limit(20)
            ->get(['id', 'name_en', 'name_am']);

        return response()->json(['data' => $rows->map(fn (Organization $o): array => ['id' => $o->id, 'name_en' => $o->name_en, 'name_am' => $o->name_am])->values()]);
    }

    /** Units of one chosen destination organization (hierarchy-valid by construction). */
    public function destinationUnits(Request $request, Organization $organization): JsonResponse
    {
        abort_unless($request->user()->can('field_work.create_own'), 403);

        $rows = OrganizationUnit::query()
            ->where('organization_id', $organization->id)
            ->where('status', 'active')
            ->orderBy('name_en')
            ->limit(500)
            ->get(['id', 'name_en', 'name_am']);

        return response()->json(['data' => $rows->map(fn (OrganizationUnit $u): array => ['id' => $u->id, 'name_en' => $u->name_en, 'name_am' => $u->name_am])->values()]);
    }

    private function list(Request $request, bool $history): Response
    {
        abort_unless($request->user()->can('field_work.view_own'), 403);
        $employee = $request->user()->employee;

        $statuses = $history
            ? array_map(static fn (FieldWorkStatus $s): string => $s->value, array_filter(FieldWorkStatus::cases(), static fn (FieldWorkStatus $s): bool => ! in_array($s, self::ACTIVE_STATUSES, true)))
            : array_map(static fn (FieldWorkStatus $s): string => $s->value, self::ACTIVE_STATUSES);

        $requests = $employee === null ? null : FieldWorkRequest::query()
            ->whereHas('participants', fn (Builder $q) => $q->where('employee_id', $employee->id))
            ->whereIn('status', $statuses)
            ->with(FieldWorkPresenter::SUMMARY_WITH)
            ->withCount('participants')
            ->orderByDesc('starts_at')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Employee/FieldWork/Index', [
            'mode' => $history ? 'history' : 'active',
            'hasEmployee' => $employee !== null,
            'requests' => $requests === null ? null : $this->paginated($requests),
            'can' => ['create' => $request->user()->can('field_work.create_own') && $employee !== null],
        ]);
    }

    /** @return array<string, mixed> */
    private function formProps(Request $request): array
    {
        $employee = $request->user()->employee;
        $assignment = $employee?->currentAssignment()->with(['organization:id,name_en,name_am', 'organizationUnit:id,name_en,name_am', 'position:id,title_en,title_am'])->first();

        return [
            'employee' => $this->presenter->employee($employee),
            'placement' => $assignment === null ? null : [
                'organization' => ['name_en' => $assignment->organization?->name_en, 'name_am' => $assignment->organization?->name_am],
                'organization_unit' => $assignment->organizationUnit ? ['name_en' => $assignment->organizationUnit->name_en, 'name_am' => $assignment->organizationUnit->name_am] : null,
                'position' => $assignment->position ? ['name_en' => $assignment->position->title_en, 'name_am' => $assignment->position->title_am] : null,
            ],
            'types' => FieldWorkType::query()->active()->ordered()->get(['id', 'name_en', 'name_am', 'description_en', 'description_am'])
                ->map(fn (FieldWorkType $t): array => ['id' => $t->id, 'name_en' => $t->name_en, 'name_am' => $t->name_am, 'description_en' => $t->description_en, 'description_am' => $t->description_am])->all(),
            'destinationTypes' => FieldWorkDestinationType::values(),
            'canAddTeam' => $request->user()->can('field_work.create_team'),
            'canSubmit' => $request->user()->can('field_work.submit_own'),
            'today' => $this->settings->today()->toDateString(),
        ];
    }

    /** A submission nobody can decide is announced as a warning, not a plain success. */
    private function afterSave(RedirectResponse $response, FieldWorkRequest $fieldWork, bool $submitted): RedirectResponse
    {
        if ($submitted && $fieldWork->supervisor_resolution === FieldWorkSupervisorResolution::NotResolved) {
            return $response->with('warning', __('field-work.supervisor_not_resolved'));
        }

        return $response->with('success', __($submitted ? 'field-work.flash.submitted' : 'field-work.flash.saved'));
    }

    private function ownsOrParticipates(Request $request, FieldWorkRequest $fieldWorkRequest): bool
    {
        $employeeId = $request->user()->employee?->id;

        return $employeeId !== null
            && $request->user()->can('field_work.view_own')
            && $fieldWorkRequest->participants()->where('employee_id', $employeeId)->exists();
    }

    private function locationFlashType(FieldWorkLocationValidation $status, bool $created): string
    {
        return ! $created || in_array($status, [FieldWorkLocationValidation::WithinExpectedArea, FieldWorkLocationValidation::CannotValidate], true) ? 'success' : 'warning';
    }

    /** @return array<string, mixed> */
    private function paginated(LengthAwarePaginator $paginator): array
    {
        return [
            'data' => collect($paginator->items())->map(fn (FieldWorkRequest $r): array => $this->presenter->summary($r))->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
                'per_page' => $paginator->perPage(),
            ],
            'links' => $paginator->linkCollection()->toArray(),
        ];
    }
}

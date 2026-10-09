<?php

declare(strict_types=1);

namespace App\Http\Controllers\Employee;

use App\Enums\FieldWorkStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\FieldWork\SaveFieldWorkRequest;
use App\Models\FieldWorkRequest;
use App\Models\FieldWorkType;
use App\Models\Organization;
use App\Services\FieldWork\FieldWorkService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Employee-only field-work surface. The actor identity is never in a URL or form field. */
class FieldWorkController extends Controller
{
    public function __construct(private readonly FieldWorkService $service) {}

    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('field_work.view_own'), 403);
        $employee = $request->user()->employee;
        $requests = $employee === null ? collect() : FieldWorkRequest::query()->where('requester_employee_id', $employee->id)->with(['fieldWorkType:id,code,name_en,name_am'])->latest('starts_at')->paginate(20)->withQueryString();

        return Inertia::render('Employee/FieldWork/Index', ['requests' => $employee === null ? null : ['data' => collect($requests->items())->map(fn (FieldWorkRequest $row) => $this->summary($row))->all(), 'meta' => ['current_page' => $requests->currentPage(), 'last_page' => $requests->lastPage(), 'total' => $requests->total()]]]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()->can('field_work.create'), 403);
        $employee = $request->user()->employee;
        abort_if($employee === null, 403, __('field-work.no_employee'));
        $assignment = $employee->currentAssignment()->with(['organization:id,name_en,name_am', 'organizationUnit:id,name_en,name_am', 'position:id,title_en,title_am'])->first();

        return Inertia::render('Employee/FieldWork/Create', ['employee' => ['name' => $employee->full_name, 'number' => $employee->employee_number, 'organization' => $assignment?->organization?->name_en, 'unit' => $assignment?->organizationUnit?->name_en, 'position' => $assignment?->position?->title_en], 'types' => FieldWorkType::query()->where('is_active', true)->orderBy('name_en')->get(['id', 'code', 'name_en', 'name_am']), 'organizations' => Organization::query()->orderBy('name_en')->limit(500)->get(['id', 'name_en', 'name_am'])]);
    }

    public function store(SaveFieldWorkRequest $request): RedirectResponse
    {
        $employee = $request->user()->employee;
        abort_if($employee === null, 403, __('field-work.no_employee'));
        $fieldWork = $this->service->createDraft($request->user(), $employee, $request->validated());

        return redirect()->route('employee.field-work.index')->with('success', __('field-work.saved', ['reference' => $fieldWork->reference_number]));
    }

    public function submit(Request $request, FieldWorkRequest $fieldWork): RedirectResponse
    {
        $this->authorize('submit', $fieldWork);
        $this->service->submit($request->user(), $fieldWork);

        return back()->with('success', __('field-work.submitted'));
    }

    public function complete(Request $request, FieldWorkRequest $fieldWork): RedirectResponse
    {
        $this->authorize('complete', $fieldWork);
        $data = $request->validate(['actual_return_at' => ['required', 'date', 'before_or_equal:now'], 'completion_note' => ['nullable', 'string', 'max:4000']]);
        $this->service->complete($request->user(), $fieldWork, now()->parse($data['actual_return_at']), $data['completion_note'] ?? null);

        return back()->with('success', __('field-work.completed'));
    }

    private function summary(FieldWorkRequest $row): array
    {
        return ['id' => $row->id, 'reference_number' => $row->reference_number, 'type' => $row->fieldWorkType?->name_en, 'destination_type' => $row->destination_type->value, 'destination' => $row->destinationOrganization?->name_en ?? $row->external_organization_name ?? $row->destination_location, 'purpose' => $row->purpose, 'starts_at' => $row->starts_at?->toIso8601String(), 'expected_return_at' => $row->expected_return_at?->toIso8601String(), 'status' => $row->status->value, 'overdue' => $row->status === FieldWorkStatus::Approved && $row->expected_return_at->isPast()];
    }
}

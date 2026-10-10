<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Cafeteria\ArchiveEmployeeCafeteriaExclusionAction;
use App\Actions\Cafeteria\CreateEmployeeCafeteriaExclusionAction;
use App\Actions\Cafeteria\EndEmployeeCafeteriaExclusionAction;
use App\Actions\Cafeteria\UpdateEmployeeCafeteriaExclusionAction;
use App\Enums\CafeteriaExclusionStatus;
use App\Enums\CafeteriaExclusionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\EndEmployeeCafeteriaExclusionRequest;
use App\Http\Requests\StoreEmployeeCafeteriaExclusionRequest;
use App\Http\Requests\UpdateEmployeeCafeteriaExclusionRequest;
use App\Http\Resources\EmployeeCafeteriaExclusionResource;
use App\Models\Employee;
use App\Models\EmployeeCafeteriaExclusion;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EmployeeCafeteriaExclusionController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', EmployeeCafeteriaExclusion::class);

        $inScope = app(OrganizationScopeService::class)->employeeIdsInScope($request->user());

        $query = EmployeeCafeteriaExclusion::query()
            ->with('employee')
            ->when($inScope !== null, fn ($q) => $q->whereIn('employee_id', $inScope))
            ->when($request->boolean('archived'), fn ($q) => $q->onlyTrashed(), fn ($q) => $q->withoutTrashed())
            ->when($request->string('status')->toString() !== '', fn ($q) => $q->where('status', $request->string('status')->toString()))
            ->when($request->string('employee_id')->toString() !== '', fn ($q) => $q->where('employee_id', $request->string('employee_id')->toString()))
            ->orderByDesc('starts_on');

        $exclusions = $query->paginate(30)->withQueryString();

        return Inertia::render('Cafeteria/EmployeeExclusions/Index', [
            'exclusions'       => EmployeeCafeteriaExclusionResource::collection($exclusions)->resolve(),
            'meta'             => ['current_page' => $exclusions->currentPage(), 'last_page' => $exclusions->lastPage(), 'total' => $exclusions->total()],
            'filters'          => $request->only(['search', 'archived', 'status']),
            'exclusion_types'  => collect(CafeteriaExclusionType::cases())->map(fn ($c) => ['value' => $c->value, 'label' => $c->label()])->values(),
            'status_options'   => collect(CafeteriaExclusionStatus::cases())->map(fn ($c) => ['value' => $c->value, 'label' => $c->label()])->values(),
            'active_count'     => EmployeeCafeteriaExclusion::query()
                ->where('status', CafeteriaExclusionStatus::Active->value)
                ->when($inScope !== null, fn ($q) => $q->whereIn('employee_id', $inScope))
                ->count(),
            'can'              => ['create' => $request->user()?->can('create', EmployeeCafeteriaExclusion::class) ?? false],
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', EmployeeCafeteriaExclusion::class);

        return Inertia::render('Cafeteria/EmployeeExclusions/Create', [
            // Employees are searched through the scoped /lookup/employees endpoint.
            'exclusion_types' => collect(CafeteriaExclusionType::cases())->map(fn ($c) => ['value' => $c->value, 'label' => $c->label()])->values(),
        ]);
    }

    public function store(StoreEmployeeCafeteriaExclusionRequest $request, CreateEmployeeCafeteriaExclusionAction $action): RedirectResponse
    {
        $this->authorize('create', EmployeeCafeteriaExclusion::class);
        $employee = Employee::query()->findOrFail($request->validated('employee_id'));
        abort_unless(app(OrganizationScopeService::class)->canAccessEmployee($request->user(), $employee), 403);

        $action->execute($request->validated(), $request->user(), $request);

        return to_route('cafeteria.employee-exclusions.index')
            ->with('flash', ['message' => __('cafeteria.exclusionCreated'), 'type' => 'success']);
    }

    public function show(EmployeeCafeteriaExclusion $employeeCafeteriaExclusion): Response
    {
        $this->authorize('view', $employeeCafeteriaExclusion);

        $employeeCafeteriaExclusion->load('employee');

        return Inertia::render('Cafeteria/EmployeeExclusions/Show', [
            'exclusion' => (new EmployeeCafeteriaExclusionResource($employeeCafeteriaExclusion))->resolve(),
        ]);
    }

    public function edit(EmployeeCafeteriaExclusion $employeeCafeteriaExclusion): Response
    {
        $this->authorize('update', $employeeCafeteriaExclusion);

        $employeeCafeteriaExclusion->load('employee');

        return Inertia::render('Cafeteria/EmployeeExclusions/Edit', [
            'exclusion'       => (new EmployeeCafeteriaExclusionResource($employeeCafeteriaExclusion))->resolve(),
            'exclusion_types' => collect(CafeteriaExclusionType::cases())->map(fn ($c) => ['value' => $c->value, 'label' => $c->label()])->values(),
        ]);
    }

    public function update(UpdateEmployeeCafeteriaExclusionRequest $request, EmployeeCafeteriaExclusion $employeeCafeteriaExclusion, UpdateEmployeeCafeteriaExclusionAction $action): RedirectResponse
    {
        $action->execute($employeeCafeteriaExclusion, $request->validated(), $request->user(), $request);

        return to_route('cafeteria.employee-exclusions.index')
            ->with('flash', ['message' => __('cafeteria.exclusionUpdated'), 'type' => 'success']);
    }

    public function end(EndEmployeeCafeteriaExclusionRequest $request, EmployeeCafeteriaExclusion $employeeCafeteriaExclusion, EndEmployeeCafeteriaExclusionAction $action): RedirectResponse
    {
        $this->authorize('end', $employeeCafeteriaExclusion);

        $action->execute($employeeCafeteriaExclusion, $request->user(), $request->string('return_to_work_on')->toString() ?: null, $request);

        return back()->with('flash', ['message' => __('cafeteria.exclusionEnded'), 'type' => 'success']);
    }

    public function archive(Request $request, EmployeeCafeteriaExclusion $employeeCafeteriaExclusion, ArchiveEmployeeCafeteriaExclusionAction $action): RedirectResponse
    {
        $this->authorize('archive', $employeeCafeteriaExclusion);

        $action->execute($employeeCafeteriaExclusion, $request->user(), $request->string('reason')->toString() ?: null, $request);

        return to_route('cafeteria.employee-exclusions.index')
            ->with('flash', ['message' => __('cafeteria.exclusionArchived'), 'type' => 'success']);
    }
}

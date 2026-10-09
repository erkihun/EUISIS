<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\IdCards\ApproveCardRequestAction;
use App\Actions\IdCards\CancelCardRequestAction;
use App\Actions\IdCards\RejectCardRequestAction;
use App\Actions\IdCards\SubmitCardRequestAction;
use App\Actions\IdCards\VerifyCardRequestDataAction;
use App\Enums\CardRequestType;
use App\Http\Controllers\Controller;
use App\Http\Requests\IdCards\ApproveCardRequestRequest;
use App\Http\Requests\IdCards\CancelCardRequestRequest;
use App\Http\Requests\IdCards\RejectCardRequestRequest;
use App\Http\Requests\IdCards\StoreCardRequestRequest;
use App\Http\Requests\IdCards\VerifyCardRequestRequest;
use App\Models\CardRequest;
use App\Models\Employee;
use App\Models\IdCard;
use App\Services\Employees\CardRequestEligibility;
use App\Services\OrganizationScope\OrganizationScopeService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CardRequestController extends Controller
{
    public function __construct(private readonly OrganizationScopeService $organizationScopeService) {}

    public function index(): Response
    {
        $this->authorize('viewAny', CardRequest::class);

        $cardRequests = CardRequest::query()
            ->whereHas('employee.currentAssignment', fn ($query) => $this->organizationScopeService->applyOrganizationScope($query, request()->user()))
            ->with(['employee.currentAssignment.organization', 'requester', 'reviewer', 'approver', 'rejecter'])
            ->orderByDesc('created_at')
            ->paginate(25);

        return Inertia::render('CardRequests/Index', [
            'cardRequests' => $cardRequests,
            'can' => [
                'create' => request()->user()?->can('create', CardRequest::class),
            ],
        ]);
    }

    public function create(CardRequestEligibility $eligibility): Response
    {
        $this->authorize('create', CardRequest::class);

        $employees = Employee::query()
            ->whereHas('currentAssignment', fn ($query) => $this->organizationScopeService->applyOrganizationScope($query, request()->user()))
            ->with('currentAssignment.organization')
            ->where('status', 'active')
            ->orderBy('full_name')
            ->get(['id', 'employee_number', 'full_name', 'status', 'current_assignment_id']);

        $cards = IdCard::query()->whereIn('employee_id', $employees->modelKeys())->get()->groupBy('employee_id');
        $pending = CardRequest::query()->whereIn('employee_id', $employees->modelKeys())
            ->whereIn('status', $eligibility->pendingStatuses())->pluck('employee_id')->flip();
        $employees->each(function (Employee $employee) use ($eligibility, $cards, $pending): void {
            $employee->setAttribute('eligible_request_types', array_values(array_map(
                fn (CardRequestType $type) => $type->value,
                array_filter(CardRequestType::cases(), fn (CardRequestType $type) => $eligibility->allows($employee, $type, $cards->get($employee->id, collect()), $pending->has($employee->id))),
            )));
        });

        return Inertia::render('CardRequests/Create', [
            'employees' => $employees,
            'requestTypes' => array_column(CardRequestType::cases(), 'value'),
        ]);
    }

    public function store(StoreCardRequestRequest $request, SubmitCardRequestAction $submitCardRequestAction, CardRequestEligibility $eligibility): RedirectResponse
    {
        $requestType = $request->filled('request_type')
            ? CardRequestType::from($request->string('request_type')->toString())
            : CardRequestType::New;

        $employeeIds = $request->validated('employee_ids') ?? [$request->validated('employee_id')];
        $employees = Employee::query()->whereIn('id', $employeeIds)->orderBy('id')->get();
        foreach ($employees as $employee) {
            $this->authorize('view', $employee);
        }

        DB::transaction(function () use ($employees, $request, $requestType, $submitCardRequestAction, $eligibility): void {
            foreach ($employees as $employee) {
                try {
                    $employee = Employee::query()->lockForUpdate()->findOrFail($employee->id);
                    $this->authorize('view', $employee);
                    $cards = IdCard::query()->where('employee_id', $employee->id)->lockForUpdate()->get();
                    $pending = $employee->cardRequests()->whereIn('status', $eligibility->pendingStatuses())->exists();
                    if (! $eligibility->allows($employee, $requestType, $cards, $pending)) {
                        throw new DomainException(__('id-cards.request_type_ineligible'));
                    }
                    $previousCard = $requestType === CardRequestType::New ? null : $eligibility->previousCard($cards);
                    $submitCardRequestAction->execute($employee, $request->user(), $request->input('reason'), $requestType, $previousCard);
                } catch (DomainException $exception) {
                    throw ValidationException::withMessages([
                        $request->has('employee_ids') ? 'employee_ids' : 'employee_id' => $employee->employee_number.': '.$exception->getMessage(),
                    ]);
                }
            }
        });

        return redirect()->route('card-requests.index')->with('success', __('id-cards.request_submitted'));
    }

    public function show(CardRequest $cardRequest): Response
    {
        $this->authorize('view', $cardRequest);

        $cardRequest->load([
            'employee.currentAssignment.organization',
            'employee.currentAssignment.position',
            'requester',
            'reviewer',
            'approver',
            'rejecter',
            'canceller',
            'previousCard',
            'cards',
        ]);

        $user = request()->user();

        return Inertia::render('CardRequests/Show', [
            'cardRequest' => $cardRequest,
            'can' => [
                'view' => $user?->can('view', $cardRequest),
                'verify' => $user?->can('verify', $cardRequest),
                'approve' => $user?->can('approve', $cardRequest),
                'reject' => $user?->can('reject', $cardRequest),
                'cancel' => $user?->can('cancel', $cardRequest),
            ],
        ]);
    }

    public function verify(VerifyCardRequestRequest $request, CardRequest $cardRequest, VerifyCardRequestDataAction $action): RedirectResponse
    {
        $action->execute($cardRequest, $request->user(), $request->input('notes'));

        return back()->with('success', __('id-cards.request_verified'));
    }

    public function approve(ApproveCardRequestRequest $request, CardRequest $cardRequest, ApproveCardRequestAction $action): RedirectResponse
    {
        $action->execute($cardRequest, $request->user(), $request->input('notes'));

        return back()->with('success', __('id-cards.request_approved'));
    }

    public function reject(RejectCardRequestRequest $request, CardRequest $cardRequest, RejectCardRequestAction $action): RedirectResponse
    {
        $action->execute($cardRequest, $request->user(), $request->string('rejection_reason')->toString());

        return back()->with('success', __('id-cards.request_rejected'));
    }

    public function cancel(CancelCardRequestRequest $request, CardRequest $cardRequest, CancelCardRequestAction $action): RedirectResponse
    {
        $action->execute($cardRequest, $request->user(), $request->input('cancellation_reason'));

        return back()->with('success', __('id-cards.request_cancelled'));
    }
}

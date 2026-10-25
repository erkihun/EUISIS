<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\CardRequests;
use App\Actions\EmployeeVerification;
use App\Actions\UpdateEmployee;
use App\Http\Controllers\Controller;
use App\Models\CardRequest;
use App\Models\Employee;
use App\Models\IdCard;
use App\Services\CoreSettings;
use App\Services\OrganizationScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Employee master-data changes and the ID card request flow. */
class EmployeeCardController extends Controller
{
    public function __construct(private readonly OrganizationScope $scope) {}

    public function updateEmployee(Request $request, Employee $employee, UpdateEmployee $update): JsonResponse
    {
        $data = $request->validate([
            'first_name' => ['sometimes', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['sometimes', 'string', 'max:255'],
            'gender' => ['nullable', Rule::in(['male', 'female'])],
        ]);

        return response()->json(['data' => $update->details($employee, $data, $request->user())->only(['id', 'employee_number', 'full_name', 'first_name', 'middle_name', 'last_name', 'gender', 'status'])]);
    }

    public function changeStatus(Request $request, Employee $employee, UpdateEmployee $update): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(UpdateEmployee::STATUSES)],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        return response()->json(['data' => $update->status($employee, $data['status'], $data['reason'], $request->user())->only(['id', 'status'])]);
    }

    /** One step of the verified-employee stage (ADR 0008): submit, verify (with the checklist), return (with a reason), approve. */
    public function workflowStep(Request $request, Employee $employee, string $step, EmployeeVerification $verification): JsonResponse
    {
        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:1000'],
            'reason' => [$step === 'return' ? 'required' : 'nullable', 'string', 'min:5', 'max:1000'],
            'checks' => ['nullable', 'array'],
            'checks.*' => ['boolean'],
        ]);
        $user = $request->user();
        $employee = match ($step) {
            'submit' => $verification->submit($employee, $user, $data['notes'] ?? null),
            'verify' => $verification->verify($employee, $user, array_map('boolval', $data['checks'] ?? []), $data['notes'] ?? null),
            'return' => $verification->returnForCorrection($employee, $user, (string) $data['reason']),
            'approve' => $verification->approve($employee, $user, $data['notes'] ?? null),
        };

        return response()->json(['data' => $employee->only(['id', 'employee_number', 'status', 'verification_status', 'submitted_at', 'verified_at', 'approved_at'])]);
    }

    /** Card requests in the reader's scope, newest first. */
    public function requests(Request $request): JsonResponse
    {
        $ids = $this->scope->organizationIds($request->user());
        $query = CardRequest::query()->with('employee:id,employee_number,full_name')
            ->when($request->filled('status'), fn ($q) => $request->input('status') === 'pending'
                ? $q->whereIn('status', CardRequest::PENDING)
                : $q->where('status', $request->string('status')))
            ->when($ids !== null, fn ($q) => $q->whereHas('employee.currentAssignment', fn ($a) => $a->whereIn('organization_id', $ids)));

        return response()->json($query->orderByDesc('submitted_at')->paginate(app(CoreSettings::class)->int('appearance.default_page_size'),
            ['id', 'employee_id', 'request_type', 'status', 'request_reason', 'requested_by', 'submitted_at', 'approved_at', 'rejection_reason', 'cancellation_reason'])->withQueryString());
    }

    /** Whether a card request of a type would be accepted now, and every reason it would not. */
    public function eligibility(Request $request, Employee $employee, CardRequests $cards): JsonResponse
    {
        $employee->load('currentAssignment');
        abort_unless($this->scope->allows($request->user(), $employee->currentAssignment?->organization_id), 404);
        $type = $request->validate(['type' => ['sometimes', Rule::in(['new', 'renewal', 'correction'])]])['type'] ?? 'new';
        $problems = $cards->eligibility($employee, $type);

        return response()->json(['eligible' => $problems === [], 'problems' => $problems]);
    }

    public function submitRequest(Request $request, Employee $employee, CardRequests $cards): JsonResponse
    {
        $data = $request->validate([
            'request_type' => ['required', Rule::in(['new', 'renewal', 'correction'])],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        return response()->json(['data' => $cards->submit($employee, $data['request_type'], $data['reason'] ?? null, $request->user())], 201);
    }

    public function decide(Request $request, CardRequest $cardRequest, string $decision, CardRequests $cards): JsonResponse
    {
        if ($decision === 'approve') {
            $data = $request->validate(['notes' => ['nullable', 'string', 'max:1000']]);
            $card = $cards->approve($cardRequest, $request->user(), $data['notes'] ?? null);

            return response()->json(['data' => $card->only(['id', 'card_number', 'status', 'expires_at'])]);
        }
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']]);

        return response()->json(['data' => $cards->close($cardRequest, $decision, $data['reason'], $request->user())->only(['id', 'status'])]);
    }

    public function replace(Request $request, IdCard $card, CardRequests $cards): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);

        return response()->json(['data' => $cards->replace($card, $data['reason'] ?? null, $request->user())->only(['id', 'status', 'request_type'])], 201);
    }
}

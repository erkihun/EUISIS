<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\CardRequests;
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

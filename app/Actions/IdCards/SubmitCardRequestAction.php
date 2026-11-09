<?php

declare(strict_types=1);

namespace App\Actions\IdCards;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\CardRequestStatus;
use App\Enums\CardRequestType;
use App\Enums\EmployeeStatus;
use App\Models\CardRequest;
use App\Models\Employee;
use App\Models\IdCard;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

readonly class SubmitCardRequestAction
{
    public function __construct(private WriteAuditLogAction $writeAuditLogAction) {}

    public function execute(
        Employee $employee,
        User $actor,
        ?string $reason = null,
        CardRequestType $requestType = CardRequestType::New,
        ?IdCard $previousCard = null,
    ): CardRequest {
        if ($employee->status !== EmployeeStatus::Active) {
            throw new DomainException('Cannot submit card request for an inactive employee.');
        }

        /*
         * Lock the employee row before reading, so two submits racing each
         * other cannot both see "no pending request" and both create one.
         * Same pattern as RegisterEmployeeAction's position-occupancy guard.
         */
        return DB::transaction(function () use ($employee, $actor, $reason, $requestType, $previousCard): CardRequest {
            Employee::query()->whereKey($employee->id)->lockForUpdate()->first();

            $hasPendingRequest = CardRequest::query()
                ->where('employee_id', $employee->id)
                ->whereIn('status', [CardRequestStatus::Draft->value, CardRequestStatus::Submitted->value, CardRequestStatus::Verified->value])
                ->exists();

            if ($hasPendingRequest) {
                throw new DomainException('Employee already has a pending card request.');
            }

            return $this->createRequest($employee, $actor, $reason, $requestType, $previousCard);
        });
    }

    private function createRequest(
        Employee $employee,
        User $actor,
        ?string $reason,
        CardRequestType $requestType,
        ?IdCard $previousCard,
    ): CardRequest {
        $request = CardRequest::query()->create([
            'employee_id' => $employee->id,
            'requested_by' => $actor->getKey(),
            'request_type' => $requestType,
            'status' => CardRequestStatus::Submitted,
            'request_reason' => $reason,
            'previous_card_id' => $previousCard?->id,
            'submitted_at' => now(),
        ]);

        $this->writeAuditLogAction->execute(
            AuditEventType::CardRequested,
            $actor,
            $request,
            $employee->currentAssignment?->organization_id,
            newValues: ['status' => CardRequestStatus::Submitted->value, 'request_type' => $requestType->value],
            reason: $reason,
        );

        return $request;
    }
}

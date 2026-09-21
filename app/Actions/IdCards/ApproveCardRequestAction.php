<?php

declare(strict_types=1);

namespace App\Actions\IdCards;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\CardRequestStatus;
use App\Enums\CardRequestType;
use App\Enums\CardStatus;
use App\Enums\EmployeeStatus;
use App\Models\CardRequest;
use App\Models\Employee;
use App\Models\IdCard;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

readonly class ApproveCardRequestAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLogAction,
        private GenerateCardNumberAction $generateCardNumberAction,
        private GenerateCardTokenAction $generateCardTokenAction,
    ) {}

    /**
     * @return array{card_request: CardRequest, card: IdCard, plaintext_token: string}
     */
    public function execute(CardRequest $cardRequest, User $actor, ?string $notes = null): array
    {
        if (! in_array($cardRequest->status, [CardRequestStatus::Submitted, CardRequestStatus::Verified], true)) {
            throw new DomainException('Card request must be in submitted or verified status to be approved. Current status: '.$cardRequest->status->value);
        }

        $employee = $cardRequest->employee;

        if ($employee->status !== EmployeeStatus::Active) {
            throw new DomainException('Cannot approve card request for an inactive employee.');
        }

        if ($employee->currentAssignment === null) {
            throw new DomainException('Employee must have a current organization assignment before card approval.');
        }

        return DB::transaction(function () use ($cardRequest, $employee, $actor, $notes): array {
            /*
             * The one-active-card invariant is checked inside the transaction,
             * behind a row lock on the employee.
             *
             * It used to be checked before the transaction opened, with no
             * lock and no unique index behind it, so two approvals for the
             * same employee racing each other both saw "no active card" and
             * both issued one — leaving an employee holding two live
             * credentials. Locking the employee row serialises concurrent
             * approvals for that employee, which is the same pattern
             * RegisterEmployeeAction already uses for position occupancy.
             */
            Employee::query()->whereKey($employee->id)->lockForUpdate()->first();

            if (! in_array($cardRequest->request_type, [CardRequestType::Replacement, CardRequestType::Lost, CardRequestType::Damaged], true)) {
                $hasActiveCard = IdCard::query()
                    ->where('employee_id', $employee->id)
                    ->whereIn('status', [CardStatus::Active->value, CardStatus::Issued->value, CardStatus::Printed->value, CardStatus::PendingPrint->value])
                    ->exists();

                if ($hasActiveCard) {
                    throw new DomainException('Employee already has an active card. Use replacement flow for a new card.');
                }
            }

            $cardRequest->update([
                'status' => CardRequestStatus::Approved,
                'approved_by' => $actor->getKey(),
                'approved_at' => now(),
                'notes' => $notes,
            ]);

            $card = IdCard::query()->create([
                'employee_id' => $cardRequest->employee_id,
                'card_request_id' => $cardRequest->id,
                'previous_card_id' => $cardRequest->previous_card_id,
                'card_number' => $this->generateCardNumberAction->execute($actor, [
                    'organization_id' => $cardRequest->employee->currentAssignment?->organization_id,
                ]),
                'status' => CardStatus::PendingPrint,
                'expires_at' => now()->addYears(2),
                'is_current' => true,
                'token_version' => 0,
                'display_snapshot' => [
                    'full_name' => $cardRequest->employee->full_name,
                    'employee_number' => $cardRequest->employee->employee_number,
                    'organization' => $cardRequest->employee->currentAssignment?->organization?->name_en,
                ],
            ]);

            $plaintextToken = $this->generateCardTokenAction->execute($card);
            $card->refresh();

            $this->writeAuditLogAction->execute(
                AuditEventType::CardApproved,
                $actor,
                $cardRequest->fresh(),
                $cardRequest->employee->currentAssignment?->organization_id,
                newValues: ['card_id' => $card->id, 'card_number' => $card->card_number, 'status' => CardStatus::PendingPrint->value],
            );

            return [
                'card_request' => $cardRequest->fresh(),
                'card' => $card,
                'plaintext_token' => $plaintextToken,
            ];
        });
    }
}

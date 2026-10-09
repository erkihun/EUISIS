<?php

declare(strict_types=1);

namespace App\Actions\IdCards;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\CardRequestStatus;
use App\Enums\CardRequestType;
use App\Enums\CardStatus;
use App\Models\CardReplacement;
use App\Models\CardRequest;
use App\Models\IdCard;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

readonly class ReplaceCardAction
{
    public function __construct(private WriteAuditLogAction $writeAuditLogAction) {}

    public function execute(IdCard $oldCard, User $actor, ?string $reason = null): CardRequest
    {
        $replaceableStatuses = [CardStatus::Lost, CardStatus::Damaged, CardStatus::Revoked, CardStatus::Expired, CardStatus::Suspended];

        if (! in_array($oldCard->status, $replaceableStatuses, true)) {
            throw new DomainException('Only lost, damaged, revoked, expired, or suspended cards can be replaced. Current status: '.$oldCard->status->value);
        }

        $hasPendingRequest = CardRequest::query()
            ->where('employee_id', $oldCard->employee_id)
            ->whereIn('status', [CardRequestStatus::Draft->value, CardRequestStatus::Submitted->value, CardRequestStatus::Verified->value])
            ->exists();

        if ($hasPendingRequest) {
            throw new DomainException('Employee already has a pending card request.');
        }

        return DB::transaction(function () use ($oldCard, $actor, $reason): CardRequest {
            $oldStatus = $oldCard->status;
            $oldCard->update([
                'status' => CardStatus::Replaced,
                'is_current' => false,
            ]);

            $requestType = match ($oldStatus) {
                CardStatus::Lost => CardRequestType::Lost,
                CardStatus::Damaged => CardRequestType::Damaged,
                default => CardRequestType::Replacement,
            };

            $cardRequest = CardRequest::query()->create([
                'employee_id' => $oldCard->employee_id,
                'requested_by' => $actor->getKey(),
                'request_type' => $requestType,
                'status' => CardRequestStatus::Submitted,
                'request_reason' => $reason ?? 'Card replacement for '.$oldCard->status->value.' card',
                'previous_card_id' => $oldCard->id,
                'submitted_at' => now(),
            ]);

            CardReplacement::query()->create([
                'old_card_id' => $oldCard->id,
                'new_card_id' => $oldCard->id, // placeholder — will be updated when new card is approved
                'reason' => $reason ?? $oldCard->status->value,
                'replaced_by' => $actor->getKey(),
                'replaced_at' => now(),
            ]);

            $this->writeAuditLogAction->execute(
                AuditEventType::CardReplaced,
                $actor,
                $oldCard->fresh(),
                $oldCard->employee?->currentAssignment?->organization_id,
                oldValues: ['status' => $oldStatus->value],
                newValues: ['status' => CardStatus::Replaced->value, 'replacement_request_id' => $cardRequest->id, 'request_type' => $requestType->value],
                reason: $reason,
            );

            return $cardRequest;
        });
    }
}

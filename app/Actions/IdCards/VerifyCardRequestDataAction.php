<?php

declare(strict_types=1);

namespace App\Actions\IdCards;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\CardRequestStatus;
use App\Models\CardRequest;
use App\Models\User;
use Illuminate\Validation\ValidationException;

readonly class VerifyCardRequestDataAction
{
    public function __construct(private WriteAuditLogAction $writeAuditLogAction) {}

    public function execute(CardRequest $cardRequest, User $actor, ?string $notes = null): CardRequest
    {
        // Verifying is the step after submission; it must never reopen a closed request.
        if ($cardRequest->status !== CardRequestStatus::Submitted) {
            throw ValidationException::withMessages([
                'status' => __('id-cards.request_not_verifiable'),
            ]);
        }

        $cardRequest->update([
            'status' => CardRequestStatus::Verified,
            'reviewed_by' => $actor->getKey(),
            'verification_notes' => $notes,
            'verified_at' => now(),
        ]);

        $fresh = $cardRequest->fresh();

        $this->writeAuditLogAction->execute(
            AuditEventType::CardVerified,
            $actor,
            $fresh,
            $fresh?->employee?->currentAssignment?->organization_id,
            newValues: $fresh?->toArray(),
            reason: $notes,
        );

        return $fresh;
    }
}

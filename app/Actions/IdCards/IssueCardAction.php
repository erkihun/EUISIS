<?php

declare(strict_types=1);

namespace App\Actions\IdCards;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\CardStatus;
use App\Models\CardIssuance;
use App\Models\IdCard;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

readonly class IssueCardAction
{
    public function __construct(private WriteAuditLogAction $writeAuditLogAction) {}

    public function execute(IdCard $card, User $actor, ?string $issuedTo = null, ?string $receivedBy = null): IdCard
    {
        if ($card->status !== CardStatus::Printed) {
            throw new DomainException('Only printed cards can be issued. Current status: '.$card->status->value);
        }

        return DB::transaction(function () use ($card, $actor, $issuedTo, $receivedBy): IdCard {
            $card->update([
                'status' => CardStatus::Issued,
                'issued_at' => now(),
            ]);

            CardIssuance::query()->create([
                'id_card_id' => $card->id,
                'issued_to' => $issuedTo ?? $card->employee?->full_name,
                'issued_by' => $actor->getKey(),
                'received_by' => $receivedBy,
                'issued_at' => now(),
                'recipient_name' => $issuedTo ?? $card->employee?->full_name,
            ]);

            $this->writeAuditLogAction->execute(
                AuditEventType::CardIssued,
                $actor,
                $card->fresh(),
                $card->employee?->currentAssignment?->organization_id,
                newValues: ['status' => CardStatus::Issued->value, 'issued_to' => $issuedTo],
            );

            return $card->fresh();
        });
    }
}

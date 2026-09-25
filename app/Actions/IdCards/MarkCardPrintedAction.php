<?php

declare(strict_types=1);

namespace App\Actions\IdCards;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\CardStatus;
use App\Models\CardPrintBatch;
use App\Models\IdCard;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

readonly class MarkCardPrintedAction
{
    public function __construct(private WriteAuditLogAction $writeAuditLogAction) {}

    public function execute(CardPrintBatch $batch, User $actor, ?string $printerNotes = null): CardPrintBatch
    {
        if (! in_array($batch->status, ['draft', 'printing'], true)) {
            throw new DomainException('Batch must be in draft or printing status to be marked printed.');
        }

        return DB::transaction(function () use ($batch, $actor, $printerNotes): CardPrintBatch {
            $now = now();
            $printedCount = 0;

            foreach ($batch->items as $item) {
                if ($item->status === 'pending') {
                    $card = IdCard::query()->find($item->id_card_id);

                    if ($card && in_array($card->status, [CardStatus::PendingPrint, CardStatus::Printed], true)) {
                        $snapshot = app(\App\Services\IdCards\IdCardFieldImpactService::class)->latest($card);
                        if (! $snapshot || $card->status === CardStatus::PendingPrint) {
                            throw \Illuminate\Validation\ValidationException::withMessages(['printer_notes' => __('employee-portal.confirm_each_card')]);
                        }

                        $item->update(['status' => 'printed']);
                        $printedCount++;

                        $this->writeAuditLogAction->execute(
                            AuditEventType::CardPrinted,
                            $actor,
                            $card,
                            $card->employee?->currentAssignment?->organization_id,
                            newValues: ['status' => CardStatus::Printed->value, 'batch_number' => $batch->batch_number],
                        );
                    }
                }
            }

            $batch->update([
                'status' => 'completed',
                'printed_at' => $now,
                'printed_by' => $actor->getKey(),
                'printer_notes' => $printerNotes,
                'printed_count' => $printedCount,
            ]);

            $this->writeAuditLogAction->execute(
                AuditEventType::PrintBatchCompleted,
                $actor,
                $batch->fresh(),
                null,
                newValues: ['batch_number' => $batch->batch_number, 'printed_count' => $printedCount],
            );

            return $batch->fresh()->load('items.card');
        });
    }
}

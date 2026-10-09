<?php

declare(strict_types=1);

namespace App\Actions\IdCards;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\CardRequestStatus;
use App\Enums\CardStatus;
use App\Models\CardPrintBatch;
use App\Models\CardRequest;
use App\Models\IdCard;
use App\Models\User;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

readonly class CreatePrintBatchAction
{
    public function __construct(private WriteAuditLogAction $writeAuditLogAction) {}

    /**
     * @param  Collection<int, IdCard>|array<string>  $cardIds  IDs of pending_print cards
     */
    public function execute(array|Collection|CardRequest $cardIds, User $actor): CardPrintBatch|IdCard
    {
        if ($cardIds instanceof CardRequest) {
            if ($cardIds->status !== CardRequestStatus::Approved) {
                throw new DomainException('Card request must be approved before printing.');
            }

            return IdCard::query()
                ->where('card_request_id', $cardIds->id)
                ->where('status', CardStatus::PendingPrint)
                ->latest('created_at')
                ->firstOrFail();
        }

        $cardIds = collect($cardIds)->values()->all();

        if (empty($cardIds)) {
            throw new DomainException('At least one card must be selected for a print batch.');
        }

        $cards = IdCard::query()
            ->with('employee.currentAssignment')
            ->whereIn('id', $cardIds)
            ->get();

        if ($cards->count() !== count($cardIds)) {
            throw new DomainException('One or more selected cards were not found.');
        }

        $notPendingPrint = $cards->filter(fn (IdCard $c) => $c->status !== CardStatus::PendingPrint);
        if ($notPendingPrint->isNotEmpty()) {
            throw new DomainException('All cards must have status pending_print to be added to a batch. Invalid cards: '.$notPendingPrint->pluck('card_number')->implode(', '));
        }

        return DB::transaction(function () use ($cards, $actor): CardPrintBatch {
            $batchNumber = 'BATCH-'.now()->format('Ymd').'-'.strtoupper(Str::random(6));

            $batch = CardPrintBatch::query()->create([
                'batch_number' => $batchNumber,
                'status' => 'draft',
                'created_by' => $actor->getKey(),
                'total_cards' => $cards->count(),
                'printed_count' => 0,
                'spoiled_count' => 0,
            ]);

            foreach ($cards as $card) {
                $batch->items()->create([
                    'id_card_id' => $card->id,
                    'card_request_id' => $card->card_request_id,
                    'status' => 'pending',
                    'spoiled' => false,
                ]);
            }

            $this->writeAuditLogAction->execute(
                AuditEventType::PrintBatchCreated,
                $actor,
                $batch,
                null,
                newValues: ['batch_number' => $batchNumber, 'total_cards' => $cards->count()],
            );

            return $batch->load('items.card');
        });
    }
}

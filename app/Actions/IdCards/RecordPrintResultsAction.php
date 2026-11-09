<?php

declare(strict_types=1);

namespace App\Actions\IdCards;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\CardPrintBatch;
use App\Models\CardPrintBatchItem;
use App\Models\User;
use App\Services\IdCards\IdCardPrintSnapshotService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records what came out of the printer, card by card. A good card is
 * confirmed through IdCardPrintSnapshotService::confirm — the same step that
 * marks a single card printed — against the snapshot the batch sent; a spoiled
 * card keeps its reason and stays printable for a new batch.
 */
final readonly class RecordPrintResultsAction
{
    public function __construct(
        private IdCardPrintSnapshotService $snapshots,
        private WriteAuditLogAction $audit,
    ) {}

    /**
     * @param  array<int, string>  $printedItemIds
     * @param  array<string, string>  $spoiled  item id => reason
     * @return array{printed: int, spoiled: int, failed: array<int, string>}
     */
    public function execute(CardPrintBatch $batch, User $actor, array $printedItemIds, array $spoiled, ?string $notes = null): array
    {
        if (! in_array($batch->status, ['draft', 'printing'], true)) {
            throw new DomainException(__('id-cards.print_batch_closed'));
        }

        $items = $batch->items()->with(['card', 'snapshot'])->where('status', 'pending')->get()->keyBy('id');
        $printed = 0;
        $failed = [];

        foreach (array_unique($printedItemIds) as $itemId) {
            /** @var CardPrintBatchItem|null $item */
            $item = $items->get($itemId);
            if ($item === null || isset($spoiled[$itemId])) {
                continue;
            }
            if ($item->snapshot === null) {
                $failed[] = (string) $item->card?->card_number;

                continue;
            }
            try {
                // Each card commits on its own, so one stale snapshot does not
                // undo the cards already confirmed around it.
                $this->snapshots->confirm($item->card, $item->snapshot, $actor);
                $item->update(['status' => 'printed', 'printed_at' => now()]);
                $printed++;
            } catch (ValidationException) {
                $failed[] = (string) $item->card?->card_number;
            }
        }

        $spoiledCount = 0;
        foreach ($spoiled as $itemId => $reason) {
            $item = $items->get($itemId);
            if ($item === null) {
                continue;
            }
            $item->update(['status' => 'spoiled', 'spoiled' => true, 'reprint_reason' => mb_substr(trim($reason), 0, 255) ?: null]);
            $spoiledCount++;
        }

        DB::transaction(function () use ($batch, $actor, $notes): void {
            $batch = CardPrintBatch::query()->lockForUpdate()->findOrFail($batch->id);
            $counts = $batch->items()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
            $done = ((int) ($counts['pending'] ?? 0)) === 0;
            $batch->update([
                'printed_count' => (int) ($counts['printed'] ?? 0),
                'spoiled_count' => (int) ($counts['spoiled'] ?? 0),
                'printer_notes' => filled($notes) ? $notes : $batch->printer_notes,
                'printed_by' => $actor->getKey(),
                'printed_at' => now(),
                'status' => $done ? 'completed' : 'printing',
            ]);
            if ($done) {
                $this->audit->execute(
                    AuditEventType::PrintBatchCompleted, $actor, $batch, null,
                    newValues: ['batch_number' => $batch->batch_number, 'printed_count' => $batch->printed_count, 'spoiled_count' => $batch->spoiled_count],
                );
            }
        });

        return ['printed' => $printed, 'spoiled' => $spoiledCount, 'failed' => $failed];
    }
}

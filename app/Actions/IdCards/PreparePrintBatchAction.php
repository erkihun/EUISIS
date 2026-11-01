<?php

declare(strict_types=1);

namespace App\Actions\IdCards;

use App\Models\CardPrintBatch;
use App\Models\CardPrintBatchItem;
use App\Models\User;
use App\Services\IdCards\IdCardPrintSnapshotService;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Freezes the card faces for a batch before it prints: each pending card gets
 * a snapshot from the same IdCardPrintSnapshotService the single-card flow
 * uses, so the print and reprint permissions, the template in force and the
 * field record are captured identically for one card or five hundred.
 *
 * Work is done a chunk at a time so a large batch never outlives one request;
 * the print station calls it until nothing remains.
 */
final readonly class PreparePrintBatchAction
{
    /** Cards frozen per call. */
    public const CHUNK = 20;

    /** A snapshot older than this cannot be confirmed (see the service), so it is redone. */
    private const FRESH_HOURS = 20;

    public function __construct(private IdCardPrintSnapshotService $snapshots) {}

    /**
     * @return array{prepared: int, remaining: int}
     */
    public function execute(CardPrintBatch $batch, User $actor, int $limit = self::CHUNK): array
    {
        if (! in_array($batch->status, ['draft', 'printing'], true)) {
            throw new DomainException(__('id-cards.print_batch_closed'));
        }

        $prepared = 0;
        foreach ($this->stale($batch)->limit($limit)->get() as $item) {
            $snapshot = $this->snapshots->prepare($item->card, $actor);
            $item->update(['id_card_print_snapshot_id' => $snapshot->id]);
            $prepared++;
        }

        if ($prepared > 0 && $batch->status === 'draft') {
            DB::table('card_print_batches')->where('id', $batch->id)->update(['status' => 'printing', 'updated_at' => now()]);
        }

        return ['prepared' => $prepared, 'remaining' => $this->stale($batch)->count()];
    }

    /**
     * Pending items with no snapshot yet, one already used for a print, or
     * one too old to confirm.
     *
     * @return \Illuminate\Database\Eloquent\Builder<CardPrintBatchItem>
     */
    public function stale(CardPrintBatch $batch)
    {
        return $batch->items()
            ->getQuery()
            ->with('card')
            ->where('status', 'pending')
            ->where(fn ($query) => $query
                ->whereNull('id_card_print_snapshot_id')
                ->orWhereHas('snapshot', fn ($snapshot) => $snapshot
                    ->whereNotNull('printed_at')
                    ->orWhere('created_at', '<', now()->subHours(self::FRESH_HOURS))))
            ->orderBy('created_at')
            ->orderBy('id');
    }
}

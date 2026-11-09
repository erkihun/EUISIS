<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CardReplacement;
use App\Models\IdCard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Reports card replacement records whose new card was never linked (before the
 * lineage fix every record kept the old card as a placeholder). With --repair
 * it links each one to the card whose previous_card_id names the old card, the
 * lineage the cards themselves already record. Nothing else is changed.
 */
class AuditCardReplacementLinks extends Command
{
    protected $signature = 'cards:audit-replacement-links {--repair : Link records to the replacement card recorded on id_cards.previous_card_id}';

    protected $description = 'Report (and optionally repair) card replacement records that still point at the old card';

    public function handle(): int
    {
        $unlinked = CardReplacement::query()->whereColumn('new_card_id', 'old_card_id')->get(['id', 'old_card_id']);
        $successors = IdCard::query()
            ->whereIn('previous_card_id', $unlinked->pluck('old_card_id')->all())
            ->orderBy('created_at')
            ->get(['id', 'previous_card_id'])
            ->groupBy('previous_card_id');

        $linkable = $unlinked->filter(fn (CardReplacement $r): bool => $successors->has($r->old_card_id));
        $this->info(sprintf('Unlinked replacement records: %d (linkable: %d, still awaiting approval or no successor: %d).',
            $unlinked->count(), $linkable->count(), $unlinked->count() - $linkable->count()));

        if (! $this->option('repair') || $linkable->isEmpty()) {
            return self::SUCCESS;
        }

        DB::transaction(function () use ($linkable, $successors): void {
            foreach ($linkable as $record) {
                CardReplacement::query()->whereKey($record->getKey())->whereColumn('new_card_id', 'old_card_id')
                    ->update(['new_card_id' => $successors->get($record->old_card_id)->first()->getKey(), 'updated_at' => now()]);
            }
        });
        $this->info(sprintf('Linked %d record(s).', $linkable->count()));

        return self::SUCCESS;
    }
}

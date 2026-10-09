<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\IdCard;
use App\Services\IdCards\CardQrPayloadService;
use Illuminate\Console\Command;

class BackfillCardPublicUuids extends Command
{
    protected $signature = 'id-cards:backfill-public-uuids';

    protected $description = 'Seed public_card_uuid for existing id_cards that do not yet have one';

    public function handle(CardQrPayloadService $qrPayloadService): int
    {
        $cards = IdCard::whereNull('public_card_uuid')->get();

        if ($cards->isEmpty()) {
            $this->info('No cards need backfilling.');
            return self::SUCCESS;
        }

        $this->info("Backfilling {$cards->count()} card(s)...");

        foreach ($cards as $card) {
            $qrPayloadService->ensurePublicReference($card);
            $this->line("  ✓ {$card->card_number}");
        }

        $this->info('Done.');
        return self::SUCCESS;
    }
}

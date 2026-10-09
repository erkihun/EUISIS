<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\IdCards\GenerateCardTokenAction;
use App\Models\IdCard;
use Illuminate\Console\Command;

class BackfillIdCardQrPayloads extends Command
{
    protected $signature = 'id-cards:backfill-qr-payloads';

    protected $description = 'Generate qr_payload for id_cards rows that have token_hash but no qr_payload';

    public function handle(GenerateCardTokenAction $action): int
    {
        $cards = IdCard::whereNotNull('token_hash')->whereNull('qr_payload')->get();

        if ($cards->isEmpty()) {
            $this->info('No cards need backfilling.');
            return self::SUCCESS;
        }

        $this->info("Backfilling {$cards->count()} card(s)...");

        foreach ($cards as $card) {
            $action->execute($card);
            $this->line("  ✓ {$card->card_number}");
        }

        $this->info('Done.');
        return self::SUCCESS;
    }
}

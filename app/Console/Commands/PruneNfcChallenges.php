<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Trims nfc_challenges to a short retention window.
 *
 * Every secure NFC tap issues a nonce row, so without pruning the table grows
 * with terminal traffic. Rows are only meaningful until they expire — a
 * consumed or expired nonce can never be replayed because verification
 * requires a row that is unconsumed AND still inside its TTL. Schedule daily.
 */
class PruneNfcChallenges extends Command
{
    protected $signature = 'nfc:prune-challenges {--hours=24 : Retention window in hours past expiry}';

    protected $description = 'Delete NFC challenge nonces that expired outside the retention window';

    public function handle(): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $cutoff = now()->subHours($hours);

        // Delete in chunks so a large backlog does not lock the table or
        // exhaust memory in one statement.
        $deleted = 0;

        do {
            $batch = DB::table('nfc_challenges')
                ->where('expires_at', '<', $cutoff)
                ->limit(1000)
                ->delete();

            $deleted += $batch;
        } while ($batch > 0);

        $this->info("Pruned {$deleted} expired NFC challenge(s) older than {$hours} hour(s).");

        return self::SUCCESS;
    }
}

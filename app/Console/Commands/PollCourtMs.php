<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\CourtMs\CourtLetterInbox;
use App\Services\CourtMs\CourtMsClient;
use App\Services\CourtMs\CourtMsException;
use App\Services\CourtMs\CourtMsStatusSync;
use Illuminate\Console\Command;

/**
 * Applies what changed on referred tribunal cases in court-ms (ADR 0013,
 * Flow 3) and receives the letters the tribunal sent institutions here
 * (Flow 5). Scheduled every ten minutes; safe to run by hand at any time.
 */
class PollCourtMs extends Command
{
    protected $signature = 'court-ms:poll';

    protected $description = 'Read referred-case updates and court letters from court-ms';

    public function handle(CourtMsClient $client, CourtMsStatusSync $sync, CourtLetterInbox $letters): int
    {
        if (! $client->enabled()) {
            $this->line('court-ms integration is off (COURT_MS_ENABLED, COURT_MS_URL, COURT_MS_TOKEN).');

            return self::SUCCESS;
        }

        try {
            $result = $sync->run();
        } catch (CourtMsException $e) {
            $this->error($e->getMessage().($e->requestId !== null ? " (request {$e->requestId})" : ''));

            return self::FAILURE;
        }

        $this->info("court-ms updates: {$result['read']} read, {$result['applied']} applied.");

        try {
            $received = $letters->run();
        } catch (CourtMsException $e) {
            $this->error('court-ms letters: '.$e->getMessage().($e->requestId !== null ? " (request {$e->requestId})" : ''));

            return self::FAILURE;
        }

        $this->info("court-ms letters: {$received['read']} read, {$received['stored']} delivered.");

        return self::SUCCESS;
    }
}

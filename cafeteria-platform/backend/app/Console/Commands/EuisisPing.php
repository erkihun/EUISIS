<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Euisis\EuisisClient;
use Illuminate\Console\Command;
use Throwable;

class EuisisPing extends Command
{
    protected $signature = 'cafeteria:euisis-ping';

    protected $description = 'Check that EUISIS answers this platform over its API with the configured token';

    public function handle(EuisisClient $euisis): int
    {
        if (! $euisis->configured()) {
            $this->error('EUISIS is not configured: set EUISIS_URL and EUISIS_TOKEN.');

            return self::FAILURE;
        }
        try {
            $body = $euisis->ping();
        } catch (Throwable $e) {
            $this->error('EUISIS did not answer: '.$e->getMessage());

            return self::FAILURE;
        }
        $this->info(sprintf('EUISIS at %s answers; %d organizations in its directory.', $euisis->baseUrl(), (int) ($body['meta']['total'] ?? count($body['data'] ?? []))));

        return self::SUCCESS;
    }
}

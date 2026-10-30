<?php

declare(strict_types=1);

namespace Modules\Cafeteria\Infrastructure\Console;

use Illuminate\Console\Command;
use Modules\Cafeteria\Infrastructure\Platform\CafeteriaPlatformClient;
use Throwable;

class CafeteriaPlatformPing extends Command
{
    protected $signature = 'cafeteria-platform:ping';

    protected $description = 'Check that the cafeteria platform answers EUISIS over its API with the configured token';

    public function handle(CafeteriaPlatformClient $platform): int
    {
        if (! $platform->configured()) {
            $this->error('The cafeteria platform is not configured: set CAFETERIA_PLATFORM_URL and CAFETERIA_PLATFORM_TOKEN.');

            return self::FAILURE;
        }
        try {
            $summary = $platform->summary();
            $providers = $platform->providers();
        } catch (Throwable $e) {
            $this->error('The cafeteria platform did not answer: '.$e->getMessage());

            return self::FAILURE;
        }
        $this->info(sprintf('%s answers (mode %s): %d cafeterias, %d meals today.', $platform->url(), config('cafeteria_platform.mode'), count($providers), (int) ($summary['meals'] ?? 0)));

        return self::SUCCESS;
    }
}

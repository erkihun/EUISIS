<?php

declare(strict_types=1);

namespace App\Console\Commands\Intelligence;

use App\Services\Intelligence\Alerts\AlertEngine;
use Illuminate\Console\Command;

/** Runs the alert engine: incidents and city-wide business insights into deduplicated alerts. Scheduled hourly. */
final class IntelligenceAlerts extends Command
{
    protected $signature = 'intelligence:alerts';

    protected $description = 'Open, refresh and resolve Intelligence alerts from incidents and business insights';

    public function handle(AlertEngine $engine): int
    {
        $result = $engine->run();
        $this->info(sprintf('Alerts opened %d, updated %d, resolved %d%s%s.', $result['opened'], $result['updated'], $result['resolved'],
            $result['skipped_business'] ? '; business alerts skipped (no active Super Admin account)' : '',
            $result['failed_areas'] === [] ? '' : '; failed areas: '.implode(', ', $result['failed_areas'])));

        return self::SUCCESS;
    }
}

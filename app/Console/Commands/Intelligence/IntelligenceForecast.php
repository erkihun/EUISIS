<?php

declare(strict_types=1);

namespace App\Console\Commands\Intelligence;

use App\Services\Intelligence\Forecasting\ForecastSnapshotService;
use App\Services\Intelligence\IntelligenceSettings;
use App\Services\Intelligence\SystemContext;
use Illuminate\Console\Command;

/**
 * Writes a city-wide forecast snapshot (and, with --evaluate, the actuals and
 * errors of months that have passed). Runs only while forecasting is enabled
 * in System Settings › Intelligence; the acting account is the first active
 * Super Admin because the platform has no system account.
 */
final class IntelligenceForecast extends Command
{
    protected $signature = 'intelligence:forecast {--evaluate : Also evaluate past predictions against actuals}';

    protected $description = 'Write Intelligence forecast snapshots and evaluate past forecasts';

    public function handle(ForecastSnapshotService $forecasts, IntelligenceSettings $settings, SystemContext $system): int
    {
        if (! $settings->forecastingEnabled()) {
            $this->warn('Forecasting is off (System Settings › Intelligence); nothing written.');

            return self::SUCCESS;
        }
        $context = $system->cityWide();
        if ($context === null) {
            $this->error('No active Super Admin account to compute with; nothing written.');

            return self::FAILURE;
        }

        if ($this->option('evaluate')) {
            $this->info('Evaluated '.$forecasts->evaluate($context)['evaluated'].' past predictions.');
        }
        $result = $forecasts->snapshot($context);
        $this->info(sprintf('Snapshot %s: %d points written%s.', $result['snapshot_id'], $result['written'], $result['insufficient'] === [] ? '' : '; insufficient history: '.implode(', ', $result['insufficient'])));

        return self::SUCCESS;
    }
}

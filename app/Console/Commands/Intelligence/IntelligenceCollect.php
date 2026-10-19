<?php

declare(strict_types=1);

namespace App\Console\Commands\Intelligence;

use App\Services\Intelligence\IntelligenceSettings;
use App\Services\Intelligence\System\Telemetry\TelemetryCollector;
use Illuminate\Console\Command;

/**
 * Samples the platform into intelligence_signals (TelemetryCollector) and,
 * with --detect, runs the detection rules right after. Scheduled every five
 * minutes, one server, no overlap.
 */
final class IntelligenceCollect extends Command
{
    protected $signature = 'intelligence:collect {--detect : Run the detection rules after collecting}';

    protected $description = 'Collect platform telemetry for Addis One Intelligence';

    public function handle(TelemetryCollector $collector, IntelligenceSettings $settings): int
    {
        if (! $settings->telemetryEnabled()) {
            $this->warn('Platform telemetry is off (System Settings › Intelligence); nothing collected.');

            return self::SUCCESS;
        }

        $result = $collector->collect();
        $this->info(sprintf('Wrote %d samples from %d probes%s.', $result['written'], $result['probes'], $result['failed'] === [] ? '' : ' (failed: '.implode(', ', $result['failed']).')'));

        if ($this->option('detect')) {
            return $this->call('intelligence:detect');
        }

        return self::SUCCESS;
    }
}

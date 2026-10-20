<?php

declare(strict_types=1);

namespace App\Console\Commands\Intelligence;

use App\Services\Intelligence\IntelligenceSettings;
use App\Services\Intelligence\System\Detection\DetectionEngine;
use Illuminate\Console\Command;

/**
 * Runs the deterministic detection rules over the collected telemetry and
 * opens, refreshes, mitigates or resolves incidents. Scheduled through
 * `intelligence:collect --detect`; runnable alone after a manual collect.
 */
final class IntelligenceDetect extends Command
{
    protected $signature = 'intelligence:detect';

    protected $description = 'Evaluate the Intelligence detection rules and update incidents';

    public function handle(DetectionEngine $engine, IntelligenceSettings $settings): int
    {
        if (! $settings->telemetryEnabled()) {
            $this->warn('Platform telemetry is off (System Settings › Intelligence); nothing to detect.');

            return self::SUCCESS;
        }

        $result = $engine->run();
        $i = $result['incidents'];
        $this->info(sprintf(
            'Rules %d (failed: %s), findings %d; incidents opened %d, recurred %d, updated %d, mitigated %d, resolved %d.',
            $result['rules'], $result['failed_rules'] === [] ? 'none' : implode(',', $result['failed_rules']), $result['findings'],
            $i['opened'], $i['recurred'], $i['updated'], $i['mitigated'], $i['resolved'],
        ));

        return self::SUCCESS;
    }
}

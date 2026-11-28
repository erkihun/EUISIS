<?php

declare(strict_types=1);

namespace Modules\Leave\Infrastructure\Console;

use App\Support\Features;
use Illuminate\Console\Command;
use Modules\Leave\Application\Services\PlannedLeaveStarter;

/**
 * Records approved planned leave as leave on its first day and takes the
 * days from the balance. Safe to run any number of times.
 */
final class StartPlannedLeaveCommand extends Command
{
    protected $signature = 'leave:start-planned';

    protected $description = 'Count approved periods of the leave schedule as leave once their first day has come';

    public function handle(PlannedLeaveStarter $starter): int
    {
        if (! Features::enabled('leave')) {
            $this->info('The leave module is switched off (FEATURE_LEAVE).');

            return self::SUCCESS;
        }
        $result = $starter->run();
        $this->info("Planned leave counted: {$result['counted']}. Could not be counted: {$result['failed']}.");

        return self::SUCCESS;
    }
}

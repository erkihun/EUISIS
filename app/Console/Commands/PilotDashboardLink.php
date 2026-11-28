<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\PilotWeek\PilotWeekProgress;
use Illuminate\Console\Command;

/** Prints the link of the temporary pilot week dashboard. */
final class PilotDashboardLink extends Command
{
    protected $signature = 'pilot:dashboard-link';

    protected $description = 'Print the unlisted link of the temporary pilot week dashboard';

    public function handle(): int
    {
        if (! PilotWeekProgress::isOpen()) {
            $this->warn('The dashboard is switched off or past its last day (config/pilot_dashboard.php).');
        }

        $this->line(route('pilot.week-dashboard', ['token' => PilotWeekProgress::token()]));
        $this->line('Open until: '.(config('pilot_dashboard.until') ?: 'no end date'));

        return self::SUCCESS;
    }
}

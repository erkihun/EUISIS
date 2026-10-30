<?php

declare(strict_types=1);

namespace Modules\Transport\Infrastructure\Console;

use App\Support\Features;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Modules\Transport\Application\Services\TransportTripPlanner;

/**
 * Plans tomorrow's (and today's missing) trips from the schedules. Runs from
 * the scheduler just after midnight and is safe to repeat: a planned trip is
 * never planned twice.
 */
class PlanTransportTrips extends Command
{
    protected $signature = 'transport:plan-trips
        {--date= : First service date (defaults to today)}
        {--days=2 : How many days from the first date to plan}
        {--provider= : Plan one provider only (uuid)}';

    protected $description = 'Create planned transport trips from the active schedules for the coming service days.';

    public function handle(TransportTripPlanner $planner): int
    {
        if (! Features::enabled('transport')) {
            $this->components->warn('Transport is disabled (FEATURE_TRANSPORT); nothing planned.');

            return self::SUCCESS;
        }

        $start = $this->option('date') ? Carbon::parse((string) $this->option('date'))->startOfDay() : today();
        $days = max(1, min(14, (int) $this->option('days')));
        $provider = $this->option('provider') ?: null;

        foreach (range(0, $days - 1) as $offset) {
            $date = $start->copy()->addDays($offset);
            $counts = $planner->planDate($date, $provider);
            $this->components->info(sprintf('%s: %d planned, %d already planned, %d not a service day', $date->toDateString(), $counts['planned'], $counts['existing'], $counts['skipped']));
        }

        return self::SUCCESS;
    }
}

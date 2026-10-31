<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Infrastructure\Console;

use Illuminate\Console\Command;
use Modules\ServiceMonitoring\Infrastructure\Jobs\SendServiceMonitoringReminders;

/** Queues the daily reminder run (D23); scheduled in routes/console.php. --sync runs it in this process. */
final class SendRemindersCommand extends Command
{
    protected $signature = 'service-monitoring:send-reminders {--sync : Run now instead of queuing}';

    protected $description = 'Send the service-monitoring reminders due today (idempotent)';

    public function handle(): int
    {
        if ($this->option('sync')) {
            SendServiceMonitoringReminders::dispatchSync();
        } else {
            SendServiceMonitoringReminders::dispatch();
        }
        $this->info('Service monitoring reminders '.($this->option('sync') ? 'sent.' : 'queued.'));

        return self::SUCCESS;
    }
}

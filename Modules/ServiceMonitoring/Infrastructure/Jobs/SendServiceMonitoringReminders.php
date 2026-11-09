<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Infrastructure\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Modules\ServiceMonitoring\Application\Services\ReminderService;

/** The daily reminder run (D23). Safe to run twice: the reminder log keeps each reminder to one per day. */
final class SendServiceMonitoringReminders implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public int $uniqueFor = 3600;

    public function handle(ReminderService $reminders): void
    {
        $sent = $reminders->run();
        Log::info('Service monitoring reminders sent.', $sent);
    }
}

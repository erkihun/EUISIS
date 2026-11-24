<?php

declare(strict_types=1);

namespace Modules\Reporting\Infrastructure\Persistence;

use Carbon\CarbonImmutable;
use Modules\Reporting\Application\Support\ReportingSettings;
use Modules\Reporting\Infrastructure\Console\RunReportingCommand;
use Modules\Reporting\Infrastructure\Persistence\Models\Artifact;
use Modules\Reporting\Infrastructure\Persistence\Models\NotificationDelivery;
use Modules\Reporting\Infrastructure\Persistence\Models\OutboxMessage;
use Modules\Reporting\Infrastructure\Persistence\Models\SchedulerRun;

/** What the settings page and institutional-reporting:health show: is the scheduled work running, and is anything stuck or failed. */
final readonly class OperationalHealth
{
    /** A task that has not completed for this long is reported stale. */
    private const STALE_AFTER_HOURS = ['cycles' => 26, 'deadlines' => 2, 'reminders' => 26, 'outbox' => 1, 'artifacts' => 1];

    public function __construct(private ReportingSettings $settings) {}

    /** @return array<string, mixed> */
    public function report(): array
    {
        $now = CarbonImmutable::now('UTC');
        $paused = $this->settings->isPaused();
        $tasks = [];
        foreach (RunReportingCommand::TASKS as $task) {
            $last = SchedulerRun::query()->where('task', $task)->orderByDesc('started_at')->first();
            $lastCompleted = SchedulerRun::query()->where('task', $task)->where('status', 'completed')->max('started_at');
            $tasks[] = [
                'task' => $task, 'status' => $last?->status, 'last_run_at' => $last?->started_at?->toIso8601String(), 'error' => $last?->error, 'stats' => $last?->stats,
                'stale' => ! $paused && ($lastCompleted === null || CarbonImmutable::parse((string) $lastCompleted, 'UTC')->lt($now->subHours(self::STALE_AFTER_HOURS[$task]))),
            ];
        }
        $outbox = [
            'pending' => OutboxMessage::query()->where('status', 'pending')->count(),
            'failed' => OutboxMessage::query()->where('status', 'failed')->count(),
            'oldest_pending_at' => OutboxMessage::query()->where('status', 'pending')->min('occurred_at'),
        ];
        $artifacts = [
            'queued' => Artifact::query()->where('status', 'queued')->count(),
            'processing' => Artifact::query()->where('status', 'processing')->count(),
            'failed' => Artifact::query()->where('status', 'failed')->count(),
        ];
        $notifications = ['failed' => NotificationDelivery::query()->where('status', 'failed')->count()];

        return [
            'paused' => $paused, 'tasks' => $tasks, 'outbox' => $outbox, 'artifacts' => $artifacts, 'notifications' => $notifications,
            'healthy' => ! in_array(true, array_column($tasks, 'stale'), true) && ! in_array('failed', array_column($tasks, 'status'), true)
                && $outbox['failed'] === 0 && $artifacts['failed'] === 0 && $notifications['failed'] === 0,
        ];
    }
}

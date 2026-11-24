<?php

declare(strict_types=1);

namespace Modules\Reporting\Infrastructure\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Modules\Reporting\Application\Support\ReportingSettings;
use Modules\Reporting\Application\UseCases\PublishCycles;
use Modules\Reporting\Application\UseCases\ScheduledWork;
use Modules\Reporting\Infrastructure\Jobs\RenderArtifact;
use Modules\Reporting\Infrastructure\Outbox\DatabaseOutbox;
use Modules\Reporting\Infrastructure\Persistence\Models\Artifact;
use Modules\Reporting\Infrastructure\Persistence\Models\SchedulerRun;
use Throwable;

/**
 * php artisan institutional-reporting:run {task?}
 *
 * The module's scheduled work, one task or all of them:
 *   cycles     publish the periods that have opened (creates the expected obligations)
 *   deadlines  stamp deadlines missed with no submission
 *   reminders  send the reminder stage that is due on each track
 *   outbox     relay integration events not yet dispatched
 *   artifacts  re-queue generated files whose job was lost or failed
 *
 * Each task is idempotent and catches up after missed runs. While the
 * maintenance switch is on, nothing runs and the run is recorded as paused.
 */
final class RunReportingCommand extends Command
{
    public const TASKS = ['cycles', 'deadlines', 'reminders', 'outbox', 'artifacts'];

    protected $signature = 'institutional-reporting:run {task? : cycles, deadlines, reminders, outbox or artifacts; all when omitted}';

    protected $description = 'Runs the Reporting module\'s scheduled work (cycles, deadlines, reminders, outbox, artifacts)';

    public function handle(ReportingSettings $settings, PublishCycles $cycles, ScheduledWork $work, DatabaseOutbox $outbox): int
    {
        $task = $this->argument('task');
        if ($task !== null && ! in_array($task, self::TASKS, true)) {
            $this->error('Unknown task. Use one of: '.implode(', ', self::TASKS));

            return self::INVALID;
        }
        $failed = false;
        foreach ($task === null ? self::TASKS : [$task] as $name) {
            $started = CarbonImmutable::now('UTC');
            if ($settings->isPaused()) {
                SchedulerRun::query()->create(['task' => $name, 'status' => 'paused', 'started_at' => $started, 'finished_at' => $started]);
                $this->line(sprintf('%-10s paused (maintenance switch is on)', $name));

                continue;
            }
            try {
                $stats = match ($name) {
                    'cycles' => $cycles->publishDue(),
                    'deadlines' => ['marked' => $work->markMissedDeadlines()],
                    'reminders' => $work->sendReminders(),
                    'outbox' => $outbox->relay(),
                    'artifacts' => ['requeued' => $this->requeueArtifacts()],
                };
                SchedulerRun::query()->create(['task' => $name, 'status' => 'completed', 'stats' => $stats, 'started_at' => $started, 'finished_at' => CarbonImmutable::now('UTC')]);
                $this->line(sprintf('%-10s %s', $name, json_encode($stats)));
            } catch (Throwable $e) {
                $failed = true;
                SchedulerRun::query()->create(['task' => $name, 'status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 2000), 'started_at' => $started, 'finished_at' => CarbonImmutable::now('UTC')]);
                $this->error(sprintf('%-10s failed: %s', $name, $e->getMessage()));
                report($e);
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** Files still queued after ten minutes, stuck in processing for half an hour, or failed fewer than five times. */
    private function requeueArtifacts(): int
    {
        $now = CarbonImmutable::now('UTC');
        $ids = Artifact::query()->where(fn ($q) => $q
            ->where(fn ($w) => $w->where('status', 'queued')->where('updated_at', '<', $now->subMinutes(10)))
            ->orWhere(fn ($w) => $w->where('status', 'processing')->where('started_at', '<', $now->subMinutes(30)))
            ->orWhere(fn ($w) => $w->where('status', 'failed')->where('attempts', '<', 5)->where('updated_at', '<', $now->subMinutes(10))))
            ->limit(200)->pluck('id');
        foreach ($ids as $id) {
            RenderArtifact::dispatch($id);
        }

        return $ids->count();
    }
}

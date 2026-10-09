<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AssessmentResponse;
use App\Models\User;
use App\Notifications\PerformanceNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Daily evaluator reminders (docs/assessment-evaluator-workflow.md#deadlines):
 * "overdue" once the due date has passed, "due soon" only inside the
 * cycle's configured reminder window. At most one reminder per assignment
 * per day, one notification per evaluator per kind, chunked for large
 * cycles. The text never contains assessment content.
 */
class SendAssessmentEvaluatorReminders extends Command
{
    protected $signature = 'assessments:evaluator-reminders {--dry-run : Count without sending}';

    protected $description = 'Remind evaluators about assessments that are due soon or overdue';

    public function handle(): int
    {
        $today = now()->toDateString();
        $sent = ['assessment_due_soon' => 0, 'assessment_overdue' => 0];

        AssessmentResponse::query()->whereIn('assessment_responses.status', ['not_started', 'in_progress', 'returned'])
            ->whereNotNull('assessment_responses.due_at')
            ->where(fn ($q) => $q->whereNull('assessment_responses.last_reminded_at')->orWhereDate('assessment_responses.last_reminded_at', '<', $today))
            ->join('assessment_records as r', 'r.id', '=', 'assessment_responses.assessment_record_id')
            ->leftJoin('assessment_cycles as c', 'c.id', '=', 'r.assessment_cycle_id')
            ->whereIn('r.status', ['assigned', 'submitted'])
            ->select(['assessment_responses.id', 'assessment_responses.evaluator_id', 'assessment_responses.due_at', 'c.reminder_days_before'])
            ->chunkById(1000, function (Collection $rows) use ($today, &$sent): void {
                $due = ['assessment_due_soon' => [], 'assessment_overdue' => []];
                foreach ($rows as $row) {
                    $dueAt = $row->due_at->toDateString();
                    if ($today > $dueAt) {
                        $due['assessment_overdue'][$row->evaluator_id][] = $row->id;
                    } elseif ($row->reminder_days_before !== null && $today >= $row->due_at->copy()->subDays((int) $row->reminder_days_before)->toDateString()) {
                        $due['assessment_due_soon'][$row->evaluator_id][] = $row->id;
                    }
                }
                foreach ($due as $kind => $byUser) {
                    if ($byUser === []) {
                        continue;
                    }
                    $sent[$kind] += count($byUser);
                    if ($this->option('dry-run')) {
                        continue;
                    }
                    $users = User::query()->whereIn('id', array_keys($byUser))->where('status', 'active')->get();
                    Notification::send($users, new PerformanceNotification($kind, route('assessment-workspace.index', ['status' => $kind === 'assessment_overdue' ? 'overdue' : 'due_soon'], false), ['database']));
                    AssessmentResponse::query()->whereIn('id', array_merge(...array_values($byUser)))->update(['last_reminded_at' => now()]);
                }
            }, 'assessment_responses.id', 'id');

        $this->info("Due soon: {$sent['assessment_due_soon']} evaluator(s). Overdue: {$sent['assessment_overdue']} evaluator(s).".($this->option('dry-run') ? ' (dry run)' : ''));

        return self::SUCCESS;
    }
}

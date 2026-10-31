<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\StatutoryDeadline;
use App\Models\User;
use App\Notifications\StatutoryDeadlineNotification;
use App\Services\Calendar\WorkingDayCalculator;
use App\Services\Legal\StatutoryDeadlineService;
use App\Support\Legal\Regulation201;
use Illuminate\Console\Command;
use Modules\Discipline\Contracts\DisciplineCases;
use Modules\Discipline\Contracts\ReadModels\DisciplinaryCaseRecord;
use Modules\Grievance\Contracts\GrievanceCaseLinks;
use Modules\Grievance\Contracts\ReadModels\GrievanceRecord;

/**
 * Warns the people who must act when a Regulation 201/2018 deadline that the
 * institution owes is two working days away, and again once it has passed.
 * Each alert is sent once per deadline. Periods that belong to the employee
 * (appealing, a late filing) or that only mark when something takes effect
 * are not alerted. Scheduled daily; never triggered by page visits.
 */
class SendStatutoryDeadlineAlerts extends Command
{
    /** Deadlines the institution itself must meet. */
    public const INSTITUTION_DUTIES = [
        Regulation201::DISCIPLINE_INVESTIGATION,
        Regulation201::DISCIPLINE_INVESTIGATION_EXTENDED,
        Regulation201::DISCIPLINE_HEAD_DECISION,
        Regulation201::DISCIPLINE_DECISION_DELIVERY,
        Regulation201::DISCIPLINE_SUSPENSION_MAX,
        Regulation201::GRIEVANCE_COMMITTEE_REPORT,
        Regulation201::GRIEVANCE_HEAD_DECISION,
        Regulation201::GRIEVANCE_DECISION_DELIVERY,
        Regulation201::APPEAL_RECORD_COPY,
        Regulation201::APPEAL_REMAND_RECONSIDERATION,
        Regulation201::APPEAL_EXECUTION,
    ];

    private const LEAD_WORKING_DAYS = 2;

    protected $signature = 'legal:statutory-deadline-alerts';

    protected $description = 'Alert responsible staff to Regulation 201/2018 deadlines that are due soon or overdue';

    public function handle(WorkingDayCalculator $calendar, StatutoryDeadlineService $deadlines, GrievanceCaseLinks $grievances, DisciplineCases $discipline): int
    {
        $now = now();
        $horizon = $calendar->addWorkingDays($now->copy(), self::LEAD_WORKING_DAYS, $deadlines->workWeekDays());
        $sent = ['due_soon' => 0, 'overdue' => 0];

        StatutoryDeadline::query()
            ->whereIn('rule_key', self::INSTITUTION_DUTIES)
            ->whereNull('completed_at')
            ->whereNull('superseded_at')
            ->where('due_at', '<=', $horizon)
            ->where(fn ($q) => $q->whereNull('due_soon_notified_at')->orWhereNull('overdue_notified_at'))
            ->orderBy('due_at')
            ->chunkById(200, function ($rows) use ($now, $grievances, $discipline, &$sent): void {
                foreach ($rows as $deadline) {
                    /** @var StatutoryDeadline $deadline */
                    $kind = $deadline->due_at->lt($now) ? 'overdue' : 'due_soon';
                    $mark = $kind === 'overdue' ? 'overdue_notified_at' : 'due_soon_notified_at';
                    if ($deadline->{$mark} !== null) {
                        continue;
                    }

                    [$caseNumber, $url, $recipients] = $this->target($deadline, $grievances, $discipline);
                    if ($caseNumber !== null) {
                        foreach ($recipients as $user) {
                            $user->notify(new StatutoryDeadlineNotification($kind, $deadline->rule_key, $caseNumber, $deadline->due_at->toDateString(), $url));
                        }
                        $sent[$kind]++;
                    }
                    // An overdue deadline never needs the earlier warning any more.
                    $deadline->forceFill([$mark => $now, ...($kind === 'overdue' && $deadline->due_soon_notified_at === null ? ['due_soon_notified_at' => $now] : [])])->save();
                }
            });

        $this->info(sprintf('Due soon: %d · overdue: %d', $sent['due_soon'], $sent['overdue']));

        return self::SUCCESS;
    }

    /** @return array{0: string|null, 1: string|null, 2: list<User>} */
    private function target(StatutoryDeadline $deadline, GrievanceCaseLinks $grievances, DisciplineCases $discipline): array
    {
        // A discipline case answers for itself (the Discipline module decides who acts on it).
        if ($deadline->subject_type === (new DisciplinaryCaseRecord)->getMorphClass()) {
            return $discipline->deadlineAlertTarget((string) $deadline->subject_id);
        }

        if ($deadline->subject_type === (new GrievanceRecord)->getMorphClass()) {
            return $grievances->deadlineAlertTarget((string) $deadline->subject_id);
        }

        return [null, null, []];
    }
}

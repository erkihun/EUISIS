<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\DisciplinaryCase;
use App\Models\Grievance;
use App\Models\StatutoryDeadline;
use App\Models\User;
use App\Notifications\StatutoryDeadlineNotification;
use App\Services\Calendar\WorkingDayCalculator;
use App\Services\Discipline\DisciplinaryCaseAccess;
use App\Services\Grievances\GrievanceNotifier;
use App\Services\Legal\StatutoryDeadlineService;
use App\Support\Legal\Regulation201;
use Illuminate\Console\Command;

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

    public function handle(WorkingDayCalculator $calendar, StatutoryDeadlineService $deadlines, GrievanceNotifier $grievanceNotifier, DisciplinaryCaseAccess $disciplineAccess): int
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
            ->chunkById(200, function ($rows) use ($now, $grievanceNotifier, $disciplineAccess, &$sent): void {
                foreach ($rows as $deadline) {
                    /** @var StatutoryDeadline $deadline */
                    $kind = $deadline->due_at->lt($now) ? 'overdue' : 'due_soon';
                    $mark = $kind === 'overdue' ? 'overdue_notified_at' : 'due_soon_notified_at';
                    if ($deadline->{$mark} !== null) {
                        continue;
                    }

                    [$caseNumber, $url, $recipients] = $this->target($deadline, $grievanceNotifier, $disciplineAccess);
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
    private function target(StatutoryDeadline $deadline, GrievanceNotifier $grievanceNotifier, DisciplinaryCaseAccess $disciplineAccess): array
    {
        $subject = $deadline->subject;

        if ($subject instanceof DisciplinaryCase) {
            if ($subject->status->isFinal()) {
                return [null, null, []];
            }
            $users = User::permission('discipline.manage')->where('status', 'active')->get()
                ->filter(fn (User $u) => $disciplineAccess->canOperate($u, $subject, 'discipline.manage'))->values()->all();

            return [(string) $subject->case_number, '/discipline/cases/'.$subject->getKey(), $users];
        }

        if ($subject instanceof Grievance) {
            $stage = $subject->currentStage;

            return [(string) $subject->reference_number, '/grievances/cases/'.$subject->getKey(), $stage === null ? [] : $grievanceNotifier->stageHandlerUsers($stage)];
        }

        return [null, null, []];
    }
}

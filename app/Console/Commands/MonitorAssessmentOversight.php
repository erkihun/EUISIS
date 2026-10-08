<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AssessmentCycle;
use App\Models\AssessmentCycleOrganization;
use App\Models\AssessmentInstitutionSubmission;
use App\Models\User;
use App\Notifications\PerformanceNotification;
use App\Services\Assessment\Oversight\AssessmentInstitutionSubmissionService;
use App\Services\Assessment\Oversight\OversightAccess;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Daily oversight compliance pass (docs/assessment-oversight.md#scheduler):
 *
 *  - marks open submissions whose source data changed as OUTDATED;
 *  - reminds institutions about due-soon (only when the cycle configures a
 *    reminder window) and overdue submissions, at most once a day each;
 *  - tells city reviewers when submissions await verification.
 *
 * Compliance detection never depends on someone opening a page.
 */
class MonitorAssessmentOversight extends Command
{
    protected $signature = 'assessments:oversight-monitor {--dry-run : Report what would happen without changing anything}';

    protected $description = 'Detect outdated institution submissions and send assessment deadline reminders';

    public function handle(AssessmentInstitutionSubmissionService $submissions, OversightAccess $access): int
    {
        $dry = (bool) $this->option('dry-run');
        $outdated = 0;
        $reminded = 0;

        AssessmentInstitutionSubmission::query()->whereIn('status', ['submitted', 'verified'])->with('cycle')->chunkById(200, function (Collection $rows) use ($submissions, $dry, &$outdated): void {
            foreach ($rows as $submission) {
                if ($dry) {
                    continue;
                }
                if ($submissions->detectOutdatedSubmission($submission)['changed']) {
                    $outdated++;
                }
            }
        });

        $today = now()->toDateString();
        AssessmentCycle::query()->where('status', 'active')->whereNotNull('submission_deadline')->each(function (AssessmentCycle $cycle) use ($today, $dry, $access, &$reminded): void {
            $deadline = $cycle->submission_deadline->toDateString();
            $dueSoonFrom = $cycle->reminder_days_before === null ? null : $cycle->submission_deadline->copy()->subDays($cycle->reminder_days_before)->toDateString();
            $kind = $today > $deadline ? 'assessment_submission_overdue' : ($dueSoonFrom !== null && $today >= $dueSoonFrom ? 'assessment_submission_due_soon' : null);
            if ($kind === null) {
                return;
            }
            $cycle->organizations()->where('status', 'included')->whereIn('submission_status', ['not_submitted', 'returned', 'outdated', 'rejected'])
                ->where(fn ($q) => $q->whereNull('last_reminded_at')->orWhereDate('last_reminded_at', '<', $today))
                ->each(function (AssessmentCycleOrganization $row) use ($cycle, $kind, $dry, $access, &$reminded): void {
                    $recipients = $this->recipients('assessment_submissions.submit', $access, $row->organization_id);
                    if ($recipients->isEmpty()) {
                        return;
                    }
                    $reminded++;
                    if (! $dry) {
                        Notification::send($recipients, new PerformanceNotification($kind, route('assessment-oversight.institution', ['organization' => $row->organization_id, 'cycle' => $cycle->id], false), ['database']));
                        $row->update(['last_reminded_at' => now()]);
                    }
                });
        });

        $pending = AssessmentInstitutionSubmission::query()->where('status', 'submitted')->count();
        if ($pending > 0 && ! $dry) {
            $reviewers = User::permission('assessment_submissions.verify')->where('status', 'active')->limit(50)->get();
            // One digest a day, not one message per submission.
            $reviewers = $reviewers->reject(fn (User $u) => $u->notifications()->where('data->kind', 'assessment_verification_pending')->whereDate('created_at', $today)->exists());
            if ($reviewers->isNotEmpty()) {
                Notification::send($reviewers, new PerformanceNotification('assessment_verification_pending', route('assessment-oversight.submissions', ['status' => 'submitted'], false), ['database']));
            }
        }

        $this->info("Outdated submissions: {$outdated}. Institution reminders: {$reminded}. Awaiting verification: {$pending}.".($dry ? ' (dry run)' : ''));

        return self::SUCCESS;
    }

    /** Active users holding the permission whose oversight scope covers the organization. */
    private function recipients(string $permission, OversightAccess $access, string $organizationId): Collection
    {
        return User::permission($permission)->where('status', 'active')->limit(200)->get()
            ->filter(fn (User $user): bool => $access->canSeeOrganization($user, $organizationId))->values();
    }
}

<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\DailyActivityStatus;
use App\Models\DailyActivityLog;
use App\Models\User;
use App\Policies\Concerns\DeniesNonAdminUsers;
use App\Services\DailyActivity\DailyActivityReviewerResolver;
use App\Services\OrganizationScope\OrganizationScopeService;

/**
 * Authorization for daily activity logs.
 *
 * Three routes to seeing a log, each checked against the RECORD, never
 * against ids the request supplied (the IDOR gate):
 *
 *   owner      -> the log's employee is the actor's own employee record
 *   reviewer   -> an effective reviewer assignment covers the log
 *   oversight  -> view_scoped and the log's snapshot organization is in scope
 *
 * Acting is narrower than seeing: only the owner edits, only an assigned
 * reviewer approves or returns, and nobody reviews their own log.
 *
 * Note: Gate::before grants Super Admin everything, so the own-log rule for
 * reviewers is re-checked in the controller as well.
 */
readonly class DailyActivityLogPolicy
{
    use DeniesNonAdminUsers;

    public function __construct(
        private DailyActivityReviewerResolver $reviewers,
        private OrganizationScopeService $scope,
    ) {}

    public function view(User $user, DailyActivityLog $log): bool
    {
        if ($this->isOwner($user, $log)) {
            return $user->can('daily_activities.view_own');
        }

        if ($user->can('daily_activities.view_team') && $this->reviewers->canReview($user, $log)) {
            return true;
        }

        return $this->inOversightScope($user, $log);
    }

    public function update(User $user, DailyActivityLog $log): bool
    {
        return $this->isOwner($user, $log)
            && $log->status->isEmployeeEditable()
            && $user->can('daily_activities.update_draft');
    }

    public function submit(User $user, DailyActivityLog $log): bool
    {
        if (! $this->isOwner($user, $log)) {
            return false;
        }

        return match ($log->status) {
            DailyActivityStatus::Draft => $user->can('daily_activities.submit'),
            DailyActivityStatus::ReturnedForCorrection => $user->can('daily_activities.resubmit'),
            default => false,
        };
    }

    public function approve(User $user, DailyActivityLog $log): bool
    {
        return $log->status->isAwaitingReview()
            && $user->can('daily_activities.approve')
            && $this->reviewers->canReview($user, $log);
    }

    public function returnForCorrection(User $user, DailyActivityLog $log): bool
    {
        return $log->status->isAwaitingReview()
            && $user->can('daily_activities.return_for_correction')
            && $this->reviewers->canReview($user, $log);
    }

    /**
     * Reopen needs its own permission AND authority over the log, either as
     * its reviewer or through organization oversight. Never one's own log.
     */
    public function reopen(User $user, DailyActivityLog $log): bool
    {
        if ($log->status !== DailyActivityStatus::Approved
            || ! $user->can('daily_activities.reopen')
            || $this->isOwner($user, $log)) {
            return false;
        }

        return $this->reviewers->canReview($user, $log) || $this->inOversightScope($user, $log);
    }

    public function manageAttachments(User $user, DailyActivityLog $log): bool
    {
        return $this->update($user, $log);
    }

    private function isOwner(User $user, DailyActivityLog $log): bool
    {
        return $this->reviewers->isOwnLog($user, $log);
    }

    private function inOversightScope(User $user, DailyActivityLog $log): bool
    {
        return $user->can('daily_activities.view_scoped')
            && $this->scope->canAccessOrganization($user, $log->organization_id);
    }
}

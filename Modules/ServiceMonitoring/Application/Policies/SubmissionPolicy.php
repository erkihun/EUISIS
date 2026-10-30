<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Application\Policies;

use App\Models\User;
use Modules\ServiceMonitoring\Application\Services\ServiceMonitoringAccess;
use Modules\ServiceMonitoring\Domain\Models\Submission;

/** A submission and everything in it (Section rows, prefill, comments, corrections) is authorized here, against its organization snapshot. Every check goes through ServiceMonitoringAccess. */
final class SubmissionPolicy
{
    public function __construct(private readonly ServiceMonitoringAccess $access) {}

    public function view(User $user, Submission $submission): bool
    {
        return $this->access->canViewSubmission($user, $submission);
    }

    public function update(User $user, Submission $submission): bool
    {
        return $this->access->canEdit($user, $submission);
    }

    public function submit(User $user, Submission $submission): bool
    {
        return $this->access->canSubmit($user, $submission);
    }

    public function review(User $user, Submission $submission): bool
    {
        return $this->access->canReview($user, $submission);
    }

    public function comment(User $user, Submission $submission): bool
    {
        return $this->access->canComment($user, $submission);
    }

    public function viewNamed(User $user, Submission $submission): bool
    {
        return $this->access->canViewNamed($user, $submission);
    }

    public function requestCorrection(User $user, Submission $submission): bool
    {
        return $this->access->canRequestCorrection($user, $submission);
    }

    public function decideCorrection(User $user, Submission $submission): bool
    {
        return $this->access->canDecideCorrection($user, $submission);
    }

    public function export(User $user, Submission $submission): bool
    {
        return $this->access->canExport($user) && $this->access->canViewSubmission($user, $submission);
    }
}

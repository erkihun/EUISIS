<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PositionServiceTask;
use App\Models\Process;
use App\Models\User;
use App\Policies\Concerns\DeniesNonAdminUsers;
use App\Services\OrganizationScope\OrganizationScopeService;

/**
 * Processes and everything under them (activities, tasks, I/O rows) follow
 * the process; standards on master tasks reuse the Daily Work standard
 * permissions. Permission says WHAT; OrganizationScopeService says WHERE.
 */
readonly class ProcessPolicy
{
    use DeniesNonAdminUsers;

    public function __construct(private OrganizationScopeService $scope) {}

    public function viewAny(User $user): bool
    {
        return $user->can('processes.viewAny');
    }

    public function view(User $user, Process $process): bool
    {
        return $user->can('processes.view') && $this->scope->canAccess($user, $process->organization_id);
    }

    public function create(User $user): bool
    {
        return $user->can('processes.create');
    }

    public function update(User $user, Process $process): bool
    {
        return $user->can('processes.update') && $this->scope->canAccess($user, $process->organization_id);
    }

    public function version(User $user, Process $process): bool
    {
        return $user->can('processes.create') && $this->scope->canAccess($user, $process->organization_id);
    }

    public function publish(User $user, Process $process): bool
    {
        return $user->can('processes.approve') && $this->scope->canAccess($user, $process->organization_id);
    }

    public function manageStandards(User $user, Process $process): bool
    {
        return $user->can('work_standards.manage') && $this->scope->canAccess($user, $process->organization_id);
    }

    public function approveStandards(User $user, Process $process): bool
    {
        return $user->can('work_standards.approve') && $this->scope->canAccess($user, $process->organization_id);
    }

    /** Tracing a position task to a master task is a design edit on the position task's organization. */
    public function linkPositionTask(User $user, PositionServiceTask $positionTask): bool
    {
        return $user->can('processes.update') && $this->scope->canAccess($user, $positionTask->organization_id);
    }
}

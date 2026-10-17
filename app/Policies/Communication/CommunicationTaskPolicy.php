<?php

declare(strict_types=1);

namespace App\Policies\Communication;

use App\Models\Communication\CommunicationTask;
use App\Models\User;

/** Tasks are seen by their assignee and creator; the assignee moves the status. */
class CommunicationTaskPolicy
{
    public function view(User $user, CommunicationTask $task): bool
    {
        return in_array($user->getKey(), [$task->assigned_to, $task->created_by], true);
    }

    public function updateStatus(User $user, CommunicationTask $task): bool
    {
        return $this->view($user, $task);
    }

    /** Reassign, change or cancel: the creator, or a task manager who created or holds it. */
    public function manage(User $user, CommunicationTask $task): bool
    {
        return $task->created_by === $user->getKey()
            || ($user->can('communication.tasks.manage') && $this->view($user, $task));
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Communication;

use App\Models\Communication\CommunicationTask;
use App\Models\User;

/**
 * Which sections of the Communication workspace a person may open. The
 * workspace has its own navigation (it is not a sidebar item); this decides
 * what that navigation offers. The routes check the same rules themselves.
 */
final class CommunicationNavigation
{
    /** @return array{channels: bool, announcements: bool, tasks: bool, moderate: bool, investigations: bool, settings: bool} */
    public function for(User $user): array
    {
        return [
            'channels' => $user->can('channels.view'),
            'announcements' => $user->can('announcements.view'),
            'tasks' => $user->can('communication.tasks.create') || CommunicationTask::query()->where('assigned_to', $user->getKey())->exists(),
            // Read from roles: the Super Admin bypass does not open moderation or investigations.
            'moderate' => $user->checkPermissionTo('communication.moderate'),
            'investigations' => $user->checkPermissionTo('communication.break_glass.request') || $user->checkPermissionTo('communication.break_glass.approve') || $user->checkPermissionTo('communication.audit'),
            'settings' => $user->can('communication.settings.manage'),
        ];
    }
}

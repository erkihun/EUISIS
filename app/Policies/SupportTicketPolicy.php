<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\SupportTicket;
use App\Models\User;
use App\Policies\Concerns\DeniesNonAdminUsers;

/**
 * Reporters see their own tickets; `support-tickets.view-all` sees every
 * ticket; `support-tickets.manage` works them (docs/assistant/support-helpdesk.md).
 * Discovered by Laravel's policy auto-discovery (App\Models\SupportTicket).
 */
final class SupportTicketPolicy
{
    use DeniesNonAdminUsers;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function viewAll(User $user): bool
    {
        return $user->can(SupportTicket::PERMISSION_VIEW_ALL) || $user->can(SupportTicket::PERMISSION_MANAGE);
    }

    public function view(User $user, SupportTicket $ticket): bool
    {
        return (int) $ticket->reporter_user_id === (int) $user->getKey() || $this->viewAll($user);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, SupportTicket $ticket): bool
    {
        return $user->can(SupportTicket::PERMISSION_MANAGE);
    }
}

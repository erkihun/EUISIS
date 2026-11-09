<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Application\Policies;

use App\Models\User;
use Modules\ServiceMonitoring\Application\Services\ServiceMonitoringAccess;
use Modules\ServiceMonitoring\Domain\Models\FormTemplate;

/** Form templates (the form builder). Every check goes through ServiceMonitoringAccess. */
final class FormTemplatePolicy
{
    public function __construct(private readonly ServiceMonitoringAccess $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->canManageForms($user);
    }

    public function view(User $user, FormTemplate $template): bool
    {
        return $this->access->canManageForms($user);
    }

    public function create(User $user): bool
    {
        return $this->access->canManageForms($user);
    }

    public function update(User $user, FormTemplate $template): bool
    {
        return $template->status->isEditable() && $this->access->canManageForms($user);
    }

    public function publish(User $user, FormTemplate $template): bool
    {
        return $template->status->isEditable() && $this->access->canManageForms($user);
    }

    public function archive(User $user, FormTemplate $template): bool
    {
        return ! $template->status->isEditable() && $this->access->canManageForms($user);
    }

    public function delete(User $user, FormTemplate $template): bool
    {
        return $template->status->isEditable() && $this->access->canManageForms($user);
    }
}

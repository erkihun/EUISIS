<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ServiceMonitoringForm;
use App\Models\User;
use App\Policies\Concerns\DeniesNonAdminUsers;

/** Building forms is a bureau duty; institutions only see published forms through their reports. */
class ServiceMonitoringFormPolicy
{
    use DeniesNonAdminUsers;

    public function viewAny(User $user): bool
    {
        return $user->can('service_monitoring.forms.manage');
    }

    public function view(User $user, ServiceMonitoringForm $form): bool
    {
        return $user->can('service_monitoring.forms.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('service_monitoring.forms.manage');
    }

    public function update(User $user, ServiceMonitoringForm $form): bool
    {
        return $user->can('service_monitoring.forms.manage') && $form->status->isEditable();
    }

    public function publish(User $user, ServiceMonitoringForm $form): bool
    {
        return $user->can('service_monitoring.forms.manage') && $form->status->isEditable();
    }

    public function archive(User $user, ServiceMonitoringForm $form): bool
    {
        return $user->can('service_monitoring.forms.manage') && ! $form->status->isEditable();
    }

    public function delete(User $user, ServiceMonitoringForm $form): bool
    {
        return $user->can('service_monitoring.forms.manage') && $form->status->isEditable();
    }
}

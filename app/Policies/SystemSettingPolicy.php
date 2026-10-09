<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\SystemSetting;
use App\Models\User;
use App\Policies\Concerns\DeniesNonAdminUsers;

class SystemSettingPolicy
{
    use DeniesNonAdminUsers;

    public function view(User $user): bool
    {
        return $user->can('system-settings.view');
    }

    public function update(User $user, SystemSetting $setting): bool
    {
        return $this->updateGroup($user, $setting->group);
    }

    public function updateGroup(User $user, string $group): bool
    {
        return match ($group) {
            'general' => $user->can('system-settings.manageGeneral'),
            'localization' => $user->can('system-settings.manageLocalization'),
            'notifications' => $user->can('system-settings.manageNotifications'),
            'email' => $user->can('system-settings.manageEmail'),
            'sms' => $user->can('system-settings.manageSms'),
            'telegram' => $user->can('system-settings.manageTelegram'),
            'security' => $user->can('system-settings.manageSecurity'),
            'appearance' => $user->can('system-settings.manageAppearance'),
            'field_work_gps' => $user->can('system-settings.manageFieldWorkGps'),
            // legacy
            'ui' => $user->can('system-settings.manageAppearance') || $user->can('system-settings.manageUi'),
            default => $user->can('system-settings.update'),
        };
    }
}

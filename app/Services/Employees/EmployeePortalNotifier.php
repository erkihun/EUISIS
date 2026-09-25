<?php

namespace App\Services\Employees;

use App\Models\IdCard;
use App\Models\User;
use App\Notifications\EmployeePortalNotification;
use App\Services\SystemSettings\SystemSettingsService;
use Illuminate\Support\Facades\Log;

class EmployeePortalNotifier
{
    public function reprint(IdCard $card): void
    {
        $settings = app(SystemSettingsService::class);
        $users = User::query()->where('status', 'active')->get();
        foreach ($users as $user) {
            $owner = $user->employee_id === $card->employee_id || ($user->employee_id === null && ! $user->employee_link_locked && $user->email === $card->employee->email);
            if (! $owner && ! $user->can('reprint', $card)) {
                continue;
            }
            $channels = [];
            $preferences = $owner ? ($card->employee->notification_preferences ?? []) : [];
            if ($settings->get('notifications', 'database_notifications_enabled', true)) {
                $channels[] = 'database';
            }
            if ($settings->get('notifications', 'email_notifications_enabled', false) && ($preferences['email'] ?? true)) {
                $channels[] = 'mail';
            }
            foreach ($channels as $channel) {
                try {
                    $user->notify((new EmployeePortalNotification($owner ? 'employee_notice' : 'officer_notice', [$channel]))->locale($owner ? $card->employee->preferred_language : app()->getLocale()));
                } catch (\Throwable) {
                    Log::warning('Employee portal notification delivery failed', ['user_id' => $user->id, 'channel' => $channel]);
                }
            }
        }
    }
}

<?php

namespace App\Services\Employees;

use App\Models\IdCard;
use App\Models\User;
use App\Notifications\EmployeePortalNotification;
use App\Services\SystemSettings\SystemSettingsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

class EmployeePortalNotifier
{
    /** Permissions that let an officer reprint a card (IdCardPolicy::reprint). */
    private const REPRINT_PERMISSIONS = ['id-cards.print', 'cards.manage'];

    /**
     * Tell the card owner and the officers who may reprint it. Candidates are
     * the owner plus accounts that can reprint at all — never every active
     * user — walked in chunks, each still passing the scoped policy check;
     * delivery is queued (EmployeePortalNotification is ShouldQueue).
     */
    public function reprint(IdCard $card): void
    {
        $settings = app(SystemSettingsService::class);
        $card->loadMissing('employee');

        $candidates = User::query()
            ->where('status', 'active')
            ->where(fn (Builder $query) => $query
                ->where('employee_id', $card->employee_id)
                ->orWhere(fn (Builder $fallback) => $fallback
                    ->whereNull('employee_id')
                    ->where('employee_link_locked', false)
                    ->where('email', $card->employee?->email))
                ->orWhereHas('permissions', fn (Builder $p) => $p->whereIn('name', self::REPRINT_PERMISSIONS))
                ->orWhereHas('roles', fn (Builder $r) => $r
                    ->where('name', 'Super Admin')
                    ->orWhereHas('permissions', fn (Builder $p) => $p->whereIn('name', self::REPRINT_PERMISSIONS))));

        $candidates->chunkById(200, function ($users) use ($card, $settings): void {
            foreach ($users as $user) {
                $this->notify($user, $card, $settings);
            }
        });
    }

    private function notify(User $user, IdCard $card, SystemSettingsService $settings): void
    {
        $owner = $user->employee_id === $card->employee_id
            || ($user->employee_id === null && ! $user->employee_link_locked && $card->employee?->email !== null && $user->email === $card->employee->email);
        if (! $owner && ! $user->can('reprint', $card)) {
            return;
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
                $user->notify((new EmployeePortalNotification($owner ? 'employee_notice' : 'officer_notice', [$channel]))
                    ->locale($owner ? $card->employee->preferred_language : app()->getLocale()));
            } catch (\Throwable) {
                Log::warning('Employee portal notification delivery failed', ['user_id' => $user->id, 'channel' => $channel]);
            }
        }
    }
}

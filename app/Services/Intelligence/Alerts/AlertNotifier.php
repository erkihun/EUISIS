<?php

declare(strict_types=1);

namespace App\Services\Intelligence\Alerts;

use App\Models\IntelligenceAlert;
use App\Models\User;
use App\Notifications\IntelligenceAlertNotification;
use App\Services\Intelligence\IntelligencePermissions;
use App\Services\Intelligence\IntelligenceSettings;
use App\Services\OrganizationScope\OrganizationScopeService;
use App\Services\SystemSettings\SystemSettingsRegistry;
use App\Services\SystemSettings\SystemSettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;
use Throwable;

/**
 * Tells city-wide executives (intelligence.executive.view, unrestricted scope)
 * about a new business alert at or above the configured severity, at most
 * once a day per alert. Technical alerts are already announced as incidents.
 * Escalation policy is NEEDS_POLICY_DECISION_ALERT_ESCALATION.
 */
final class AlertNotifier
{
    public const COOLDOWN_HOURS = 24;

    private const RANK = ['info' => 0, 'low' => 1, 'medium' => 2, 'high' => 3, 'critical' => 4];

    public function __construct(
        private readonly IntelligenceSettings $settings,
        private readonly SystemSettingsService $systemSettings,
        private readonly OrganizationScopeService $scope,
    ) {}

    public function opened(IntelligenceAlert $alert): void
    {
        $minimum = $this->settings->notifyMinSeverity();
        if ($minimum === 'none' || (self::RANK[$alert->severity] ?? 0) < (self::RANK[$minimum] ?? 99)) {
            return;
        }
        if ($alert->last_notified_at !== null && $alert->last_notified_at->gt(now()->subHours(self::COOLDOWN_HOURS))) {
            return;
        }
        try {
            $users = User::query()->permission(IntelligencePermissions::EXECUTIVE)->where('status', 'active')->get()
                ->filter(fn (User $u): bool => $this->scope->isUnrestricted($u));
        } catch (PermissionDoesNotExist) {
            return;
        }
        if ($users->isEmpty()) {
            return;
        }
        $alert->forceFill(['last_notified_at' => now()])->save();
        $ids = $users->pluck('id')->all();

        DB::afterCommit(function () use ($ids, $alert): void {
            foreach (User::query()->whereKey($ids)->get() as $user) {
                $channels = $this->channels($user);
                if ($channels === []) {
                    continue;
                }
                try {
                    $user->notify(IntelligenceAlertNotification::for($alert, $channels));
                } catch (Throwable $e) {
                    Log::warning('Intelligence alert notification failed', ['alert' => $alert->id, 'user_id' => $user->id, 'error' => $e->getMessage()]);
                }
            }
        });
    }

    /** @return list<string> */
    private function channels(User $user): array
    {
        $group = SystemSettingsRegistry::GROUP_NOTIFICATIONS;
        $channels = [];
        if (filter_var($this->systemSettings->get($group, 'database_notifications_enabled', true), FILTER_VALIDATE_BOOLEAN)) {
            $channels[] = 'database';
        }
        if (filter_var($this->systemSettings->get($group, 'email_notifications_enabled', false), FILTER_VALIDATE_BOOLEAN) && $user->email) {
            $channels[] = 'mail';
        }

        return $channels;
    }
}

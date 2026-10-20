<?php

declare(strict_types=1);

namespace App\Services\Intelligence\System;

use App\Models\IntelligenceIncident;
use App\Models\User;
use App\Notifications\IntelligenceIncidentNotification;
use App\Services\Intelligence\IntelligencePermissions;
use App\Services\Intelligence\IntelligenceSettings;
use App\Services\SystemSettings\SystemSettingsRegistry;
use App\Services\SystemSettings\SystemSettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;
use Throwable;

/**
 * Tells the authorized technical users about an incident at or above the
 * configured severity, once per hour per incident. Who is escalated to after
 * that, and on which channel, is NEEDS_POLICY_DECISION_ALERT_ESCALATION; no
 * policy is invented here.
 */
final class IncidentNotifier
{
    public const COOLDOWN_MINUTES = 60;

    private const RANK = ['info' => 0, 'low' => 1, 'medium' => 2, 'high' => 3, 'critical' => 4];

    public function __construct(
        private readonly IntelligenceSettings $settings,
        private readonly SystemSettingsService $systemSettings,
    ) {}

    public function opened(IntelligenceIncident $incident): void
    {
        $this->notify($incident, 'opened');
    }

    public function escalated(IntelligenceIncident $incident): void
    {
        $this->notify($incident, 'escalated');
    }

    private function notify(IntelligenceIncident $incident, string $event): void
    {
        $minimum = $this->settings->notifyMinSeverity();
        if ($minimum === 'none' || (self::RANK[$incident->severity] ?? 0) < (self::RANK[$minimum] ?? 99)) {
            return;
        }
        if ($incident->last_notified_at !== null && $incident->last_notified_at->gt(now()->subMinutes(self::COOLDOWN_MINUTES))) {
            return;
        }

        try {
            $users = User::query()->permission(IntelligencePermissions::INCIDENTS)->where('status', 'active')->get();
        } catch (PermissionDoesNotExist) {
            return;
        }
        if ($users->isEmpty()) {
            return;
        }

        $incident->forceFill(['last_notified_at' => now()])->save();
        $ids = $users->pluck('id')->all();

        DB::afterCommit(function () use ($ids, $incident, $event): void {
            foreach (User::query()->whereKey($ids)->get() as $user) {
                $channels = $this->channels($user);
                if ($channels === []) {
                    continue;
                }
                try {
                    $user->notify(IntelligenceIncidentNotification::for($incident, $event, $channels));
                } catch (Throwable $e) {
                    Log::warning('Intelligence incident notification failed', ['incident' => $incident->reference, 'user_id' => $user->id, 'error' => $e->getMessage()]);
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

<?php

declare(strict_types=1);

namespace App\Services\FieldWork;

use App\Models\FieldWorkRequest;
use App\Models\User;
use App\Notifications\FieldWorkNotification;
use App\Services\DailyActivity\DailyActivityNotifier;
use App\Services\SystemSettings\SystemSettingsRegistry;
use App\Services\SystemSettings\SystemSettingsService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Delivers Field Work notices through the system notification channels.
 * The requester is reached through the same employee → user link as every
 * other module (DailyActivityNotifier::userFor). Failures are logged and
 * swallowed: a mail outage must never undo an approval.
 */
class FieldWorkNotifier
{
    public function __construct(
        private readonly SystemSettingsService $settings,
        private readonly DailyActivityNotifier $users,
    ) {}

    public function approvalRequired(FieldWorkRequest $request): void
    {
        if ($request->supervisor_user_id !== null) {
            $this->send(User::query()->whereKey($request->supervisor_user_id)->where('status', 'active')->first(), 'approval_required', $request, null);
        }
    }

    public function decided(FieldWorkRequest $request, string $kind, ?string $comment): void
    {
        $request->loadMissing('requester');
        $this->send($this->users->userFor($request->requester), $kind, $request, $comment);
    }

    private function send(?User $user, string $kind, FieldWorkRequest $request, ?string $comment): void
    {
        $channels = $this->channelsFor($user);
        if ($user === null || $channels === []) {
            return;
        }

        try {
            $user->notify(new FieldWorkNotification($kind, $request->id, $request->reference_number, $comment, $channels));
        } catch (Throwable $exception) {
            Log::warning('Field work notification failed', ['field_work_request_id' => $request->id, 'kind' => $kind, 'exception' => $exception->getMessage()]);
        }
    }

    /** @return array<int, string> */
    private function channelsFor(?User $user): array
    {
        if ($user === null) {
            return [];
        }

        $group = SystemSettingsRegistry::GROUP_NOTIFICATIONS;
        $channels = [];
        if (filter_var($this->settings->get($group, 'database_notifications_enabled', true), FILTER_VALIDATE_BOOLEAN)) {
            $channels[] = 'database';
        }
        if (filter_var($this->settings->get($group, 'email_notifications_enabled', false), FILTER_VALIDATE_BOOLEAN) && $user->email) {
            $channels[] = 'mail';
        }

        return $channels;
    }
}

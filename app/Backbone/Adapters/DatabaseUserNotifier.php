<?php

declare(strict_types=1);

namespace App\Backbone\Adapters;

use App\Backbone\Contracts\UserNotifier;
use App\Models\User;
use Illuminate\Notifications\Notification;

/** Notifies active users through Laravel's notification channels. */
final readonly class DatabaseUserNotifier implements UserNotifier
{
    public function notify(array $userIds, Notification $notification): array
    {
        $userIds = array_values(array_unique(array_filter(array_map(intval(...), $userIds))));
        if ($userIds === []) {
            return [];
        }
        $notified = [];
        foreach (User::query()->whereKey($userIds)->where('status', 'active')->get() as $user) {
            // A clone per person: Laravel stamps the notification with an id when it is sent.
            $user->notify(clone $notification);
            $notified[] = (int) $user->getKey();
        }

        return $notified;
    }
}

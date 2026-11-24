<?php

declare(strict_types=1);

namespace App\Backbone\Contracts;

use Illuminate\Notifications\Notification;

/**
 * Hands a module's notification to users named by id. Inactive and unknown
 * users are skipped. Delivery (bell, e-mail) is the notification's own
 * `via()`; nothing here says a message was read.
 */
interface UserNotifier
{
    /**
     * @param  list<int>  $userIds
     * @return list<int> the users the notification was handed to
     */
    public function notify(array $userIds, Notification $notification): array;
}

<?php

declare(strict_types=1);

namespace Modules\Reporting\Infrastructure\Notifications;

use App\Backbone\Contracts\UserNotifier;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Modules\Reporting\Application\Ports\NotificationPublisher;
use Modules\Reporting\Application\Support\ReportingSettings;
use Modules\Reporting\Infrastructure\Persistence\Models\NotificationDelivery;
use Throwable;

/**
 * Sends each notice to each person once. The delivery row is inserted first,
 * under a unique key; only the caller that wins the insert hands the notice
 * over, so repeated runs, retried jobs and replayed events cannot duplicate
 * it. A hand-over failure is recorded on the row (visible on the health page)
 * rather than retried blindly.
 */
final readonly class DeduplicatedNotificationPublisher implements NotificationPublisher
{
    public function __construct(private UserNotifier $notifier, private ReportingSettings $settings) {}

    public function publish(string $track, string $stage, string $deliveryKey, array $userIds, array $params, ?string $obligationId = null): int
    {
        $channels = $this->settings->get('email_notifications') ? ['database', 'mail'] : ['database'];
        $kind = $track.'_'.(str_starts_with($stage, 'before_') ? 'before' : $stage);
        if (str_starts_with($stage, 'before_')) {
            $params['days'] = substr($stage, 7);
        }
        $path = $obligationId === null ? '/reporting' : "/reporting/obligations/{$obligationId}";
        $sent = 0;

        foreach (array_values(array_unique(array_filter(array_map(intval(...), $userIds)))) as $userId) {
            try {
                // A savepoint: a duplicate key must not poison a surrounding transaction.
                $delivery = DB::transaction(fn (): NotificationDelivery => NotificationDelivery::query()->create([
                    'delivery_key' => "{$deliveryKey}:{$userId}", 'track' => $track, 'stage' => $stage, 'obligation_id' => $obligationId,
                    'recipient_user_id' => $userId, 'channels' => $channels, 'status' => 'requested', 'created_at' => CarbonImmutable::now('UTC'),
                ]));
            } catch (UniqueConstraintViolationException) {
                continue;
            }
            try {
                $handed = $this->notifier->notify([$userId], new ReportingNotification($kind, $params, $path, $channels));
                $delivery->forceFill(['status' => $handed === [] ? 'skipped' : 'handed_over'])->save();
                $sent += count($handed);
            } catch (Throwable $e) {
                $delivery->forceFill(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 1000)])->save();
            }
        }

        return $sent;
    }
}

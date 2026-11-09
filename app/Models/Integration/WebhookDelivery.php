<?php

declare(strict_types=1);

namespace App\Models\Integration;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One event owed to one subscription. A replay adds a new generation with
 * the same event_id — the business event is never re-created.
 *
 * pending → in_flight → succeeded
 *                     → retrying → … → dead (max attempts or permanent refusal)
 *                     → skipped (the subscription may no longer see the event)
 */
class WebhookDelivery extends Model
{
    use HasUuidPrimaryKey;

    public const STATUS_PENDING = 'pending';

    public const STATUS_IN_FLIGHT = 'in_flight';

    public const STATUS_RETRYING = 'retrying';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_DEAD = 'dead';

    public const STATUS_SKIPPED = 'skipped';

    /** Statuses that still owe the partner a delivery. */
    public const OPEN = [self::STATUS_PENDING, self::STATUS_IN_FLIGHT, self::STATUS_RETRYING];

    protected $fillable = [
        'webhook_subscription_id', 'integration_event_id', 'event_id', 'feed_position', 'aggregate_key', 'generation',
        'replay_id', 'status', 'attempt_count', 'next_attempt_at', 'locked_until', 'last_http_status',
        'last_error_category', 'last_latency_ms', 'first_attempt_at', 'last_attempt_at', 'delivered_at',
    ];

    protected function casts(): array
    {
        return [
            'feed_position' => 'integer',
            'generation' => 'integer',
            'attempt_count' => 'integer',
            'last_http_status' => 'integer',
            'last_latency_ms' => 'integer',
            'next_attempt_at' => 'datetime',
            'locked_until' => 'datetime',
            'first_attempt_at' => 'datetime',
            'last_attempt_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(WebhookSubscription::class, 'webhook_subscription_id');
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(IntegrationEvent::class, 'integration_event_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(WebhookDeliveryAttempt::class)->orderBy('attempt');
    }
}

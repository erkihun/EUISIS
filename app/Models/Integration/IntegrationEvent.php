<?php

declare(strict_types=1);

namespace App\Models\Integration;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One outbox row = one external integration event.
 *
 * Written inside the domain transaction (IntegrationEventRecorder), so a
 * rolled-back change leaves no event. The publisher later stamps
 * `feed_position`; only positioned rows are visible in the change feed or
 * delivered to webhooks. Rows are never rewritten after publication — a
 * webhook replay reuses the same row and event_id.
 *
 * `payload` holds only whitelisted, minimised fields (EventCatalog);
 * `metadata` is internal diagnostics (request/actor reference) and is never
 * serialised to partners.
 */
class IntegrationEvent extends Model
{
    public const UPDATED_AT = null;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PUBLISHED = 'published';

    /** Failed schema validation at publish time; never delivered, visible to admins. */
    public const STATUS_REJECTED = 'rejected';

    public const KIND_UPSERT = 'upsert';

    public const KIND_TOMBSTONE = 'tombstone';

    protected $table = 'integration_events';

    protected $fillable = [
        'event_id', 'event_type', 'event_version', 'aggregate_type', 'aggregate_uuid', 'sequence',
        'organization_id', 'related_organization_id', 'is_global', 'change_kind', 'occurred_at', 'effective_at',
        'correlation_id', 'causation_id', 'payload', 'metadata', 'status', 'attempt_count', 'available_at',
        'published_at', 'feed_position', 'error',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'metadata' => 'array',
            'is_global' => 'boolean',
            'event_version' => 'integer',
            'sequence' => 'integer',
            'feed_position' => 'integer',
            'attempt_count' => 'integer',
            'occurred_at' => 'datetime',
            'available_at' => 'datetime',
            'published_at' => 'datetime',
            'effective_at' => 'date:Y-m-d',
        ];
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->whereNotNull('feed_position');
    }

    public function aggregateKey(): string
    {
        return $this->aggregate_type.':'.$this->aggregate_uuid;
    }
}

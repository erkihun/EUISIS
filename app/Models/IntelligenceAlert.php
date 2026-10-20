<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One deduplicated alert (docs/intelligence-alerts.md): a condition from an
 * incident, a business insight, a data-quality check or a forecast, with its
 * severity, evidence, recommendation, occurrence count and the people who
 * acknowledged or resolved it. `organization_id` null means city-wide.
 */
class IntelligenceAlert extends Model
{
    use HasUuidPrimaryKey;

    public const TYPES = ['incident', 'insight', 'data_quality', 'forecast'];

    public const DOMAINS = ['business', 'technical'];

    public const STATUSES = ['open', 'acknowledged', 'resolved'];

    public const ACTIVE_STATUSES = ['open', 'acknowledged'];

    protected $fillable = [
        'type', 'domain', 'source_key', 'severity', 'status', 'title', 'params', 'evidence', 'recommendation', 'href', 'organization_id', 'area',
        'occurrences', 'clear_runs', 'first_detected_at', 'last_detected_at', 'acknowledged_by', 'acknowledged_at', 'resolved_by', 'resolved_at', 'cooldown_until', 'last_notified_at',
    ];

    protected function casts(): array
    {
        return [
            'params' => 'array',
            'evidence' => 'array',
            'occurrences' => 'integer',
            'clear_runs' => 'integer',
            'first_detected_at' => 'datetime',
            'last_detected_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'resolved_at' => 'datetime',
            'cooldown_until' => 'datetime',
            'last_notified_at' => 'datetime',
        ];
    }

    public function acknowledger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}

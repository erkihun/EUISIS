<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A repeated error signature: exception class + normalised message + route
 * group, counted over time. The sample context holds a redacted, relative
 * file and line and the top frames' classes — never arguments, headers,
 * input or secrets (docs/intelligence-system-health.md).
 */
class IntelligenceErrorFingerprint extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'fingerprint', 'exception_class', 'message_normalized', 'route_name', 'route_group', 'component',
        'last_status', 'last_error_id', 'sample_context', 'occurrences', 'first_seen_at', 'last_seen_at', 'resolved_at', 'incident_id',
    ];

    protected function casts(): array
    {
        return [
            'sample_context' => 'array',
            'occurrences' => 'integer',
            'last_status' => 'integer',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(IntelligenceIncident::class, 'incident_id');
    }
}

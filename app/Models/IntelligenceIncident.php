<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A technical incident the detection engine opened from correlated signals
 * (docs/intelligence-system-health.md). Severity comes from the rule and the
 * measured impact, never from a model; status follows
 * open → investigating → mitigated → resolved → closed, with every change
 * recorded as an immutable event.
 */
class IntelligenceIncident extends Model
{
    use HasUuidPrimaryKey;

    public const SEVERITIES = ['critical', 'high', 'medium', 'low', 'info'];

    public const STATUSES = ['open', 'investigating', 'mitigated', 'resolved', 'closed'];

    /** Statuses in which the incident still counts as active. */
    public const ACTIVE_STATUSES = ['open', 'investigating', 'mitigated'];

    public const VERIFICATIONS = ['pending', 'verified', 'failed'];

    protected $fillable = [
        'reference', 'title', 'severity', 'status', 'component', 'module', 'endpoint', 'fingerprint', 'rule',
        'detected_at', 'first_seen_at', 'last_seen_at', 'mitigated_at', 'resolved_at', 'closed_at', 'occurrences',
        'clear_runs', 'last_notified_at', 'impact_summary', 'evidence', 'source_signals', 'probable_cause', 'cause_confidence', 'recommendation',
        'deployment_id', 'owner_user_id', 'resolution_summary', 'verification_status', 'ai_run_id',
    ];

    protected function casts(): array
    {
        return [
            'evidence' => 'array',
            'source_signals' => 'array',
            'recommendation' => 'array',
            'occurrences' => 'integer',
            'clear_runs' => 'integer',
            'last_notified_at' => 'datetime',
            'detected_at' => 'datetime',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'mitigated_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function events(): HasMany
    {
        return $this->hasMany(IntelligenceIncidentEvent::class, 'incident_id')->orderBy('occurred_at');
    }

    public function recommendations(): HasMany
    {
        return $this->hasMany(IntelligenceRecommendation::class, 'incident_id')->orderByDesc('generated_at');
    }

    public function fingerprints(): HasMany
    {
        return $this->hasMany(IntelligenceErrorFingerprint::class, 'incident_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function deployment(): BelongsTo
    {
        return $this->belongsTo(IntelligenceDeployment::class, 'deployment_id');
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }
}

<?php

declare(strict_types=1);

namespace App\Models\Integration;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\ExternalApplication;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A partner application's push endpoint and the event types it receives.
 *
 * Secrets are encrypted at rest and hidden from serialisation; the plaintext
 * is shown exactly once, when created or rotated. During a rotation the
 * previous secret keeps signing (a second `v1=` signature) until
 * previous_secret_expires_at, so a partner can switch without downtime.
 */
class WebhookSubscription extends Model
{
    use HasUuidPrimaryKey;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DISABLED = 'disabled';

    protected $fillable = [
        'external_application_id', 'name', 'callback_url', 'event_types', 'status', 'secret', 'previous_secret',
        'previous_secret_expires_at', 'secret_rotated_at', 'start_after_position', 'consecutive_failures',
        'last_success_at', 'last_failure_at', 'last_failure_category', 'created_by', 'updated_by',
    ];

    protected $hidden = ['secret', 'previous_secret'];

    protected function casts(): array
    {
        return [
            'event_types' => 'array',
            'secret' => 'encrypted',
            'previous_secret' => 'encrypted',
            'previous_secret_expires_at' => 'datetime',
            'secret_rotated_at' => 'datetime',
            'last_success_at' => 'datetime',
            'last_failure_at' => 'datetime',
            'start_after_position' => 'integer',
            'consecutive_failures' => 'integer',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(ExternalApplication::class, 'external_application_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /** @return list<string> secrets that sign right now, newest first */
    public function signingSecrets(): array
    {
        $secrets = [(string) $this->secret];

        if ($this->previous_secret !== null && $this->previous_secret_expires_at !== null && $this->previous_secret_expires_at->isFuture()) {
            $secrets[] = (string) $this->previous_secret;
        }

        return $secrets;
    }

    /**
     * healthy / degraded / failing / unknown — from real delivery records only.
     * One transient failure never marks a partner down.
     */
    public function health(): string
    {
        if ($this->last_success_at === null && $this->last_failure_at === null) {
            return 'unknown';
        }

        $threshold = max(1, (int) config('integration.webhooks.degraded_after_failures', 3));

        return match (true) {
            $this->consecutive_failures === 0 => 'healthy',
            $this->consecutive_failures < $threshold => 'healthy',
            $this->consecutive_failures < $threshold * 3 => 'degraded',
            default => 'failing',
        };
    }
}

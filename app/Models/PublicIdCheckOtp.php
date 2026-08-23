<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A one-time code issued for a public ID check.
 *
 * The plaintext code exists only in the notification sent to the employee; this
 * row stores its hash. `updated_at` is deliberately absent — a row is written
 * once and only ever touched to record a verification or a failed attempt.
 */
class PublicIdCheckOtp extends Model
{
    use HasUuidPrimaryKey;

    public const MAX_ATTEMPTS = 5;

    public const TTL_MINUTES = 5;

    public const UPDATED_AT = null;

    protected $fillable = [
        'id_card_id',
        'card_uuid',
        'otp_hash',
        'expires_at',
        'verified_at',
        'attempts',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    public function idCard(): BelongsTo
    {
        return $this->belongsTo(IdCard::class, 'id_card_id');
    }

    /** Still open: not yet verified, not expired, attempts left. */
    public function scopeUsable(Builder $query): Builder
    {
        return $query->whereNull('verified_at')
            ->where('expires_at', '>', now())
            ->where('attempts', '<', self::MAX_ATTEMPTS);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function hasAttemptsLeft(): bool
    {
        return $this->attempts < self::MAX_ATTEMPTS;
    }
}

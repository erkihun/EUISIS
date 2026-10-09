<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A one-time code a provider user proves email/phone possession with to reset their password. */
class ProviderPasswordResetCode extends Model
{
    use HasUuidPrimaryKey;

    public const MAX_ATTEMPTS = 5;

    public const TTL_MINUTES = 10;

    /** Minimum gap between two codes for one account (SMS cost, inbox flooding). */
    public const RESEND_SECONDS = 60;

    public const UPDATED_AT = null;

    protected $fillable = [
        'provider_user_id',
        'channel',
        'otp_hash',
        'expires_at',
        'used_at',
        'attempts',
        'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    public function providerUser(): BelongsTo
    {
        return $this->belongsTo(ProviderUser::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at === null || $this->expires_at->isPast();
    }

    public function hasAttemptsLeft(): bool
    {
        return $this->attempts < self::MAX_ATTEMPTS;
    }
}

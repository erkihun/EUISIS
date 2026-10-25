<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Emergency (break-glass) access (ADR 0010): extra permissions and scope for
 * one person, for a limited time, approved by someone else. Requested →
 * active (approved) or rejected; active → expired (its time ran out) or
 * revoked (ended early).
 */
class BreakGlassGrant extends Model
{
    use HasUuids;

    public const REQUESTED = 'requested';

    public const ACTIVE = 'active';

    public const REJECTED = 'rejected';

    public const EXPIRED = 'expired';

    public const REVOKED = 'revoked';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'permissions' => 'array', 'duration_minutes' => 'integer', 'requested_at' => 'datetime', 'decided_at' => 'datetime',
            'starts_at' => 'datetime', 'expires_at' => 'datetime', 'ended_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @param Builder<self> $query */
    public function scopeInForce(Builder $query): Builder
    {
        return $query->where('status', self::ACTIVE)->where('starts_at', '<=', now())->where('expires_at', '>', now());
    }
}

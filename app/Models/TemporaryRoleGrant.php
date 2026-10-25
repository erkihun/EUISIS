<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A role held for a limited time (break-glass elevation). The role itself is a
 * normal role assignment; this row records why, who approved it, and when it
 * ends, and access:expire-elevations removes the role at expires_at.
 */
class TemporaryRoleGrant extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'user_id', 'role_name', 'reason', 'granted_by', 'approved_by',
        'granted_at', 'expires_at', 'ended_at', 'end_reason',
    ];

    protected function casts(): array
    {
        return [
            'granted_at' => 'datetime',
            'expires_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @param Builder<self> $query */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('ended_at');
    }
}

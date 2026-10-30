<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Acting/delegated grievance authority for a period. Created by a settings manager, never by the delegate (no self-delegation).
 */
class GrievanceDelegation extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'grievance_delegations';

    protected $fillable = [
        'delegator_user_id',
        'delegate_user_id',
        'authority',
        'organization_id',
        'position_id',
        'starts_at',
        'ends_at',
        'reason',
        'status',
        'created_by',
        'revoked_by',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function delegator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegator_user_id');
    }

    public function delegate(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegate_user_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class, 'position_id');
    }
}

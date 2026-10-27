<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Historical pivot: admin user ↔ cafeteria provider assignment.
 * Previously stored in cafeteria_provider_users; now in cafeteria_provider_assignments.
 * Provider portal authentication now uses CafeteriaProviderUser via the cafeteria_provider guard.
 */
class CafeteriaProviderAssignment extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'cafeteria_provider_assignments';

    protected $fillable = [
        'cafeteria_provider_id',
        'organization_id',
        'cafeteria_provider_branch_id',
        'user_id',
        'service_provider_user_id',
        'role',
        'provider_role',
        'is_active',
        'assigned_by',
        'effective_from',
        'effective_to',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(CafeteriaProvider::class, 'cafeteria_provider_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(CafeteriaProviderBranch::class, 'cafeteria_provider_branch_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function serviceProviderUser(): BelongsTo
    {
        return $this->belongsTo(ServiceProviderUser::class);
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Grievance\GrievanceReferralStatus;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An explicit referral of a grievance outcome to a disciplinary process. The grievance itself is never converted into a disciplinary case.
 */
class GrievanceDisciplinaryReferral extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'grievance_disciplinary_referrals';

    protected $fillable = [
        'grievance_id',
        'decision_id',
        'referred_to_organization_id',
        'referred_to_organization_unit_id',
        'reason',
        'status',
        'disciplinary_case_reference',
        'disciplinary_case_id',
        'referred_by',
        'referred_at',
        'acknowledged_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => GrievanceReferralStatus::class,
            'referred_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function grievance(): BelongsTo
    {
        return $this->belongsTo(Grievance::class, 'grievance_id');
    }

    public function decision(): BelongsTo
    {
        return $this->belongsTo(GrievanceDecision::class, 'decision_id');
    }

    public function referredToOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'referred_to_organization_id');
    }

    public function referredToUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationUnit::class, 'referred_to_organization_unit_id');
    }
}

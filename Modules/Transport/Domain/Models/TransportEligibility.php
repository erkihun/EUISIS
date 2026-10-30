<?php

declare(strict_types=1);

namespace Modules\Transport\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Providers\Domain\Models\Provider;
use Modules\Transport\Domain\Enums\TransportEligibilityStatus;
use Modules\Transport\Domain\Enums\TransportEligibilitySubject;

/**
 * Who qualifies for transport, by decision of the city: one employee, or an
 * office (self / subtree). Carries the authorization that made it so. The
 * employee's default route and stops are the pass (TransportPass).
 */
class TransportEligibility extends Model
{
    use HasUuidPrimaryKey;
    use SoftDeletes;

    protected $fillable = [
        'subject_type', 'employee_id', 'organization_id', 'scope_type', 'provider_id', 'transport_contract_id', 'status',
        'effective_from', 'effective_to', 'authorization_reference', 'authorized_by', 'notes', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'subject_type' => TransportEligibilitySubject::class,
            'status' => TransportEligibilityStatus::class,
            'effective_from' => 'date:Y-m-d',
            'effective_to' => 'date:Y-m-d',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(TransportContract::class, 'transport_contract_id');
    }

    public function authorizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authorized_by');
    }

    /** @param  Builder<self>  $query */
    public function scopeEffectiveOn(Builder $query, string $date): Builder
    {
        return $query->where('status', TransportEligibilityStatus::Active->value)
            ->whereDate('effective_from', '<=', $date)
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date));
    }
}

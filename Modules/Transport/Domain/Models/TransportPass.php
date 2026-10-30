<?php

declare(strict_types=1);

namespace Modules\Transport\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\Employee;
use App\Models\Provider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The employee's route assignment: which provider and route they ride by
 * default, their morning boarding stop and evening stop, and until when.
 * Boarding never rotates the card or employee identity.
 */
class TransportPass extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'employee_id',
        'provider_id',
        'transport_route_id',
        'boarding_stop_id',
        'evening_stop_id',
        'transport_eligibility_id',
        'valid_from',
        'valid_until',
        'status',
        'suspended_reason',
        'suspended_at',
        'issued_by',
        'issued_at',
        'notes',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'valid_from' => 'date:Y-m-d',
            'valid_until' => 'date:Y-m-d',
            'issued_at' => 'datetime',
            'suspended_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(TransportRoute::class, 'transport_route_id');
    }

    public function boardingStop(): BelongsTo
    {
        return $this->belongsTo(TransportRouteStop::class, 'boarding_stop_id');
    }

    public function eveningStop(): BelongsTo
    {
        return $this->belongsTo(TransportRouteStop::class, 'evening_stop_id');
    }

    public function eligibility(): BelongsTo
    {
        return $this->belongsTo(TransportEligibility::class, 'transport_eligibility_id');
    }

    /** Active and valid on the date. @param  Builder<self>  $query */
    public function scopeValidOn(Builder $query, string $date): Builder
    {
        return $query->where('status', 'active')
            ->whereDate('valid_from', '<=', $date)
            ->where(fn (Builder $q) => $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', $date));
    }
}

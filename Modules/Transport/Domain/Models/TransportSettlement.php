<?php

declare(strict_types=1);

namespace Modules\Transport\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\Organization;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Transport\Domain\Enums\TransportSettlementStatus;

/**
 * The statement the city computes for a provider and period: the verified
 * basis (boardings, employee-days, trips) from immutable evidence, the
 * amount the contract formula yields, the provider's claim, the approved
 * adjustments and the amount finally approved.
 */
class TransportSettlement extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'settlement_number', 'provider_id', 'transport_contract_id', 'transport_invoice_id', 'organization_id', 'period_start', 'period_end',
        'status', 'contract_snapshot', 'basis', 'verified_boardings', 'unique_riders', 'employee_days', 'completed_trips', 'cancelled_trips',
        'currency_code', 'calculated_amount', 'claimed_amount', 'adjustment_total', 'approved_amount', 'computed_by', 'computed_at',
        'reviewed_by', 'reviewed_at', 'approved_by', 'approved_at', 'rejected_reason', 'payment_reference', 'payment_marked_at',
        'payment_marked_by', 'notes', 'policy_gaps',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date:Y-m-d',
            'period_end' => 'date:Y-m-d',
            'status' => TransportSettlementStatus::class,
            'contract_snapshot' => 'array',
            'basis' => 'array',
            'policy_gaps' => 'array',
            'verified_boardings' => 'integer',
            'unique_riders' => 'integer',
            'employee_days' => 'integer',
            'completed_trips' => 'integer',
            'cancelled_trips' => 'integer',
            'calculated_amount' => 'decimal:2',
            'claimed_amount' => 'decimal:2',
            'adjustment_total' => 'decimal:2',
            'approved_amount' => 'decimal:2',
            'computed_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'approved_at' => 'datetime',
            'payment_marked_at' => 'datetime',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(TransportContract::class, 'transport_contract_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(TransportInvoice::class, 'transport_invoice_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(TransportSettlementAdjustment::class, 'transport_settlement_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(TransportTransaction::class, 'transport_settlement_id');
    }

    public function computedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'computed_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Transport\TransportInvoiceStatus;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The provider's claim for a period. Claimed ≠ verified ≠ approved: all three are kept. */
class TransportInvoice extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'invoice_number', 'provider_id', 'transport_contract_id', 'period_start', 'period_end', 'claimed_boardings', 'claimed_trips',
        'claimed_employee_days', 'claimed_amount', 'currency_code', 'status', 'attachment_path', 'notes', 'submitted_by_provider_user_id',
        'submitted_at', 'returned_reason', 'reviewed_by', 'reviewed_at', 'transport_settlement_id',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'claimed_boardings' => 'integer',
            'claimed_trips' => 'integer',
            'claimed_employee_days' => 'integer',
            'claimed_amount' => 'decimal:2',
            'status' => TransportInvoiceStatus::class,
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
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

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(TransportSettlement::class, 'transport_settlement_id');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(ProviderUser::class, 'submitted_by_provider_user_id');
    }
}

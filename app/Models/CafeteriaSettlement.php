<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CafeteriaSettlementStatus;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** What one provider (the payee) is owed for a period, split by employee organization and cafeteria. */
class CafeteriaSettlement extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'settlement_number',
        'provider_id',
        'period_start',
        'period_end',
        'status',
        'transaction_count',
        'total_subsidy_amount',
        'total_employee_amount',
        'total_provider_amount',
        'currency_code',
        'notes',
        'generated_by',
        'generated_at',
        'finalized_by',
        'finalized_at',
        'cancelled_by',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'status' => CafeteriaSettlementStatus::class,
            'transaction_count' => 'integer',
            'total_subsidy_amount' => 'decimal:2',
            'total_employee_amount' => 'decimal:2',
            'total_provider_amount' => 'decimal:2',
            'generated_at' => 'datetime',
            'finalized_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CafeteriaSettlementLine::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(CafeteriaTransaction::class);
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function finalizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }
}

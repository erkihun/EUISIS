<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One employee organization (billing owner) at one service cafeteria within a settlement. */
class CafeteriaSettlementLine extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'cafeteria_settlement_id',
        'employee_organization_id',
        'cafeteria_id',
        'transaction_count',
        'subsidy_amount',
        'employee_amount',
        'provider_amount',
    ];

    protected function casts(): array
    {
        return [
            'transaction_count' => 'integer',
            'subsidy_amount' => 'decimal:2',
            'employee_amount' => 'decimal:2',
            'provider_amount' => 'decimal:2',
        ];
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(CafeteriaSettlement::class, 'cafeteria_settlement_id');
    }

    public function employeeOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'employee_organization_id');
    }

    public function cafeteria(): BelongsTo
    {
        return $this->belongsTo(CafeteriaProvider::class, 'cafeteria_id');
    }
}

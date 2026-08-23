<?php

declare(strict_types=1);

namespace CafeteriaSystem\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CafeteriaSubsidyLedger extends Model
{
    use HasUuids;

    protected $table = 'cafeteria_subsidy_ledger';

    protected $fillable = [
        'provider_id',
        'cafeteria_id',
        'transaction_id',
        'employee_number',
        'employee_name',
        'organization_code',
        'entry_type',
        'amount',
        'balance_after',
        'entry_date',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'entry_date' => 'date',
        ];
    }
}

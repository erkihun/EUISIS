<?php

declare(strict_types=1);

namespace CafeteriaSystem\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CafeteriaServiceTransaction extends Model
{
    use HasUuids;

    protected $table = 'cafeteria_service_transactions';

    protected $fillable = [
        'transaction_number',
        'provider_id',
        'cafeteria_id',
        'organization_code',
        'employee_number',
        'employee_name',
        'organization_name',
        'card_status',
        'card_token_hash',
        'eligibility_result',
        'service_type',
        'usage_mode',
        'service_date',
        'blocked_reason',
        'status',
        'meal_amount',
        'subsidy_amount',
        'employee_payable',
        'served_at',
        'served_by_user_id',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'served_at' => 'datetime',
            'service_date' => 'date',
            'meal_amount' => 'decimal:2',
            'subsidy_amount' => 'decimal:2',
            'employee_payable' => 'decimal:2',
        ];
    }
}

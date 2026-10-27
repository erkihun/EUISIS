<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CafeteriaFoodOrder extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'order_number',
        'cafeteria_provider_id',
        'cafeteria_menu_id',
        'employee_id',
        'id_card_id',
        'fulfilled_transaction_id',
        'order_date',
        'ordered_at',
        'served_at',
        'fulfillment_nonce',
        'status',
        'quantity',
        'total_amount',
        'subsidy_amount_applied',
        'employee_payable_amount',
        'notes',
        'cancellation_reason',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'order_date' => 'date',
            'ordered_at' => 'datetime',
            'served_at' => 'datetime',
            'quantity' => 'integer',
            'total_amount' => 'decimal:2',
            'subsidy_amount_applied' => 'decimal:2',
            'employee_payable_amount' => 'decimal:2',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(CafeteriaProvider::class, 'cafeteria_provider_id');
    }

    public function menu(): BelongsTo
    {
        return $this->belongsTo(CafeteriaMenu::class, 'cafeteria_menu_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function idCard(): BelongsTo
    {
        return $this->belongsTo(IdCard::class, 'id_card_id');
    }

    public function fulfilledTransaction(): BelongsTo
    {
        return $this->belongsTo(CafeteriaTransaction::class, 'fulfilled_transaction_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(CafeteriaFoodOrderItem::class);
    }
}

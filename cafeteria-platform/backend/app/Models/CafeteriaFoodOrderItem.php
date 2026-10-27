<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CafeteriaFoodOrderItem extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'cafeteria_food_order_id',
        'cafeteria_menu_item_id',
        'quantity',
        'unit_price',
        'total_price',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'total_price' => 'decimal:2',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(CafeteriaFoodOrder::class, 'cafeteria_food_order_id');
    }

    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(CafeteriaMenuItem::class, 'cafeteria_menu_item_id');
    }
}

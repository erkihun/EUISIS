<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CafeteriaMenu extends Model
{
    use HasUuidPrimaryKey;
    use SoftDeletes;

    protected $fillable = [
        'cafeteria_provider_id',
        'menu_date',
        'title_en',
        'title_am',
        'description_en',
        'description_am',
        'meal_type',
        'price',
        'subsidy_eligible',
        'max_orders',
        'order_cutoff_at',
        'status',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'menu_date' => 'date',
            'price' => 'decimal:2',
            'subsidy_eligible' => 'boolean',
            'max_orders' => 'integer',
            'order_cutoff_at' => 'datetime',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(CafeteriaProvider::class, 'cafeteria_provider_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(CafeteriaMenuItem::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(CafeteriaFoodOrder::class);
    }
}

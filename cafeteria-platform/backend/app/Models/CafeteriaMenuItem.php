<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CafeteriaMenuItem extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'cafeteria_menu_id',
        'name_en',
        'name_am',
        'description_en',
        'description_am',
        'item_type',
        'is_available',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_available' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function menu(): BelongsTo
    {
        return $this->belongsTo(CafeteriaMenu::class, 'cafeteria_menu_id');
    }
}

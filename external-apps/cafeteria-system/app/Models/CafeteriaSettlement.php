<?php

declare(strict_types=1);

namespace CafeteriaSystem\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CafeteriaSettlement extends Model
{
    use HasUuids;

    protected $table = 'cafeteria_settlements';

    protected $fillable = [
        'provider_id',
        'period_start',
        'period_end',
        'transaction_count',
        'total_amount',
        'total_subsidy',
        'status',
        'generated_at',
        'exported_at',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'transaction_count' => 'integer',
            'total_amount' => 'decimal:2',
            'total_subsidy' => 'decimal:2',
            'generated_at' => 'datetime',
            'exported_at' => 'datetime',
        ];
    }
}

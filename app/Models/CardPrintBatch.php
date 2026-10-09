<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CardPrintBatch extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'batch_number',
        'status',
        'created_by',
        'printed_at',
        'printed_by',
        'printer_notes',
        'total_cards',
        'printed_count',
        'spoiled_count',
    ];

    protected function casts(): array
    {
        return [
            'printed_at' => 'datetime',
            'total_cards' => 'integer',
            'printed_count' => 'integer',
            'spoiled_count' => 'integer',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function printer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'printed_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(CardPrintBatchItem::class, 'card_print_batch_id');
    }
}

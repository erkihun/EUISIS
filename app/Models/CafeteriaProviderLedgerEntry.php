<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CafeteriaProviderLedgerEntry extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'cafeteria_provider_id',
        'cafeteria_transaction_id',
        'entry_date',
        'entry_type',
        'debit',
        'credit',
        'balance_after',
        'description',
        'created_by',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'debit' => 'decimal:2',
            'credit' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'metadata' => 'array',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(CafeteriaProvider::class, 'cafeteria_provider_id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(CafeteriaTransaction::class, 'cafeteria_transaction_id');
    }
}

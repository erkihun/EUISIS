<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CardPrintBatchItem extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'card_print_batch_id',
        'id_card_id',
        'card_request_id',
        'status',
        'spoiled',
        'reprint_reason',
    ];

    protected function casts(): array
    {
        return [
            'spoiled' => 'bool',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(CardPrintBatch::class, 'card_print_batch_id');
    }

    public function card(): BelongsTo
    {
        return $this->belongsTo(IdCard::class, 'id_card_id');
    }

    public function cardRequest(): BelongsTo
    {
        return $this->belongsTo(CardRequest::class);
    }
}

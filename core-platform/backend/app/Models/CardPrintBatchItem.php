<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One card in a print batch: a first print or a reprint, queued, printed or spoiled, with its quality check. */
class CardPrintBatchItem extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['qc_at' => 'datetime'];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(CardPrintBatch::class, 'card_print_batch_id');
    }

    public function card(): BelongsTo
    {
        return $this->belongsTo(IdCard::class, 'id_card_id');
    }
}

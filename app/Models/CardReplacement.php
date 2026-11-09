<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CardReplacement extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'old_card_id',
        'new_card_id',
        'reason',
        'replaced_at',
        'replaced_by',
    ];

    protected function casts(): array
    {
        return [
            'replaced_at' => 'datetime',
        ];
    }

    public function oldCard(): BelongsTo
    {
        return $this->belongsTo(IdCard::class, 'old_card_id');
    }

    public function newCard(): BelongsTo
    {
        return $this->belongsTo(IdCard::class, 'new_card_id');
    }

    public function replacer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'replaced_by');
    }
}

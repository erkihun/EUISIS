<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A numbered batch of cards printed together (ADR 0010): draft → completed, or cancelled. */
class CardPrintBatch extends Model
{
    use HasUuids;

    public const DRAFT = 'draft';

    public const COMPLETED = 'completed';

    public const CANCELLED = 'cancelled';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['printed_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(CardPrintBatchItem::class);
    }

    public function templateVersion(): BelongsTo
    {
        return $this->belongsTo(CardTemplateVersion::class, 'card_template_version_id');
    }
}

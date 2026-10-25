<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One version of a card template's layout (ADR 0010): a draft is edited,
 * an active one is what new cards are printed with (one at a time), and a
 * retired one is kept because printed cards name it.
 */
class CardTemplateVersion extends Model
{
    use HasUuids;

    public const DRAFT = 'draft';

    public const ACTIVE = 'active';

    public const RETIRED = 'retired';

    protected $guarded = ['id', 'active_flag'];

    protected function casts(): array
    {
        return ['config' => 'array', 'version' => 'integer', 'activated_at' => 'datetime', 'retired_at' => 'datetime'];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(CardTemplate::class, 'card_template_id');
    }

    public static function active(): ?self
    {
        return self::query()->where('status', self::ACTIVE)->first();
    }
}

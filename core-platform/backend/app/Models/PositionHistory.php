<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One material change of a position (ADR 0010): what changed, from what to what, by whom, from when. Never updated. */
class PositionHistory extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['changes' => 'array', 'effective_from' => 'date'];
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }
}

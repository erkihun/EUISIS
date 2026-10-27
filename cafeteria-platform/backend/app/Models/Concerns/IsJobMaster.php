<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Position;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A job master value (ADR 0010): a unique code, English and Amharic names, an
 * order and an active flag. A value in use is deactivated, never deleted.
 */
trait IsJobMaster
{
    use HasUuids;

    public function initializeIsJobMaster(): void
    {
        $this->fillable = ['code', 'name_en', 'name_am', 'description', 'sort_order', 'is_active', 'needs_review'];
        $this->mergeCasts(['is_active' => 'boolean', 'needs_review' => 'boolean', 'sort_order' => 'integer']);
    }

    /** The positions that use this value (the column is the model's POSITION_COLUMN). */
    public function positions(): HasMany
    {
        return $this->hasMany(Position::class, static::POSITION_COLUMN);
    }
}

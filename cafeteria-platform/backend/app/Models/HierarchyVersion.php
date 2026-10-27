<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class HierarchyVersion extends Model
{
    use HasUuids;

    public const PUBLISHED = 'published';

    public const ARCHIVED = 'archived';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['effective_from' => 'date', 'effective_to' => 'date', 'approval_date' => 'datetime', 'reviewed_at' => 'datetime'];
    }

    /** The structure in force: the latest published version. */
    public static function current(): ?self
    {
        return self::query()->where('status', self::PUBLISHED)
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', today()))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', today()))
            ->latest('effective_from')->latest('created_at')->latest('id')->first();
    }

    /** The structure that was in force on a past day (published or since archived). */
    public static function inForceOn(string $day): ?self
    {
        return self::query()->whereIn('status', [self::PUBLISHED, self::ARCHIVED])
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', $day))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $day))
            ->latest('effective_from')->latest('created_at')->latest('id')->first();
    }
}

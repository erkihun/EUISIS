<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class HierarchyVersion extends Model
{
    use HasUuids;

    public const PUBLISHED = 'published';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['effective_from' => 'date', 'effective_to' => 'date', 'approval_date' => 'datetime'];
    }

    /** The structure in force: the latest published version. */
    public static function current(): ?self
    {
        return self::query()->where('status', self::PUBLISHED)->latest('effective_from')->latest('created_at')->first();
    }
}

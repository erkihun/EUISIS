<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One ISCO group (major → sub-major → minor → unit) of a revision. Reference
 * data loaded from the official dataset, never typed in by hand (D-12).
 */
class IscoGroup extends Model
{
    use HasUuidPrimaryKey;

    public const LEVELS = ['major', 'sub_major', 'minor', 'unit'];

    protected $fillable = ['revision', 'code', 'level', 'parent_id', 'name_en', 'name_am', 'description_en', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('code');
    }

    public function occupations(): HasMany
    {
        return $this->hasMany(Occupation::class, 'isco_group_id');
    }
}

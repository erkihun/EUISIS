<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Configurable catalog of field work kinds (Inspection, Supervision, …).
 * Deactivated rather than deleted once used, so history keeps its label.
 */
class FieldWorkType extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'code',
        'name_en',
        'name_am',
        'description_en',
        'description_am',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'bool',
            'sort_order' => 'integer',
        ];
    }

    public function requests(): HasMany
    {
        return $this->hasMany(FieldWorkRequest::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name_en');
    }
}

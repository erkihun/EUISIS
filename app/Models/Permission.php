<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Spatie\Permission\Models\Permission as SpatiePermission;

class Permission extends SpatiePermission
{
    protected $fillable = [
        'name',
        'guard_name',
        'label_en',
        'label_am',
        'description_en',
        'description_am',
        'group',
        'sort_order',
        'is_system',
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function getLabelAttribute(): string
    {
        $locale = app()->getLocale();

        return ($locale === 'am' ? $this->label_am : null) ?? $this->label_en ?? $this->name;
    }

    public function getDescriptionAttribute(): ?string
    {
        $locale = app()->getLocale();

        return ($locale === 'am' ? $this->description_am : null) ?? $this->description_en;
    }

    public function scopeForGroup(Builder $query, string $group): Builder
    {
        return $query->where('group', $group);
    }

    public function scopeSystem(Builder $query): Builder
    {
        return $query->where('is_system', true);
    }

    public function scopeCustom(Builder $query): Builder
    {
        return $query->where('is_system', false);
    }
}

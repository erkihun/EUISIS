<?php
// app/Models/Permission.php

namespace Modules\Court\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Permission extends Model
{
    protected $table = 'court_permissions';

    protected $fillable = [
        'name',
        'label',
        'description',
        'label_translations',
        'description_translations',
    ];

    protected $casts = [
        'label_translations' => 'array',
        'description_translations' => 'array',
    ];

    public function getLabelLocalizedAttribute(): ?string
    {
        return $this->resolveTranslation('label');
    }

    public function getDescriptionLocalizedAttribute(): ?string
    {
        return $this->resolveTranslation('description');
    }

    protected function resolveTranslation(string $attribute): ?string
    {
        $translations = $this->{"{$attribute}_translations"} ?? [];
        $locale = app()->getLocale();

        if (!empty($translations[$locale])) {
            return $translations[$locale];
        }

        $primaryLocale = config('app.locale', 'en');

        if (!empty($translations[$primaryLocale])) {
            return $translations[$primaryLocale];
        }

        return $this->{$attribute} ?? null;
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'court_permission_role')->withTimestamps();
    }
}

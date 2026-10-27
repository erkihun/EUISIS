<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A card design (ADR 0010); its layouts are numbered versions. */
class CardTemplate extends Model
{
    use HasUuids;

    protected $fillable = ['code', 'name_en', 'name_am', 'description', 'created_by'];

    public function versions(): HasMany
    {
        return $this->hasMany(CardTemplateVersion::class)->orderByDesc('version');
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** CORE / TECHNICAL / … (brief §30): the kind of a competency. */
class CompetencyType extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = ['code', 'name_en', 'name_am', 'description_en', 'description_am', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    public function competencies(): HasMany
    {
        return $this->hasMany(Competency::class);
    }
}

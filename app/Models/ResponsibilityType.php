<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;

/** RESPONSIBLE / ACCOUNTABLE / CONSULTED / SUPPORTING … (brief §38), configurable city-level. */
class ResponsibilityType extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = ['code', 'name_en', 'name_am', 'description_en', 'description_am', 'is_exclusive', 'covers_object', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return ['is_exclusive' => 'boolean', 'covers_object' => 'boolean', 'is_active' => 'boolean', 'sort_order' => 'integer'];
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;

/** Catalog of legal instruments a mandate can rest on (proclamation, regulation, …). */
class LegalInstrumentType extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = ['code', 'name_en', 'name_am', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['sort_order' => 'integer', 'is_active' => 'boolean'];
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;

/** The qualification ladder (certificate … doctorate) a requirement points at (brief §34). Ranked, editable catalog. */
class EducationLevel extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = ['code', 'name_en', 'name_am', 'rank', 'is_active'];

    protected function casts(): array
    {
        return ['rank' => 'integer', 'is_active' => 'boolean'];
    }
}

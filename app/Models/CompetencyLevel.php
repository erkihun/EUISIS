<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;

/**
 * A named competency level (Foundational … Expert) with a numeric rank so
 * a required level can be compared with EPMS competency ratings (D-13).
 */
class CompetencyLevel extends Model
{
    use HasUuidPrimaryKey;

    public const MAX_RANK = 10;

    protected $fillable = ['code', 'name_en', 'name_am', 'description_en', 'description_am', 'rank', 'is_active'];

    protected function casts(): array
    {
        return ['rank' => 'integer', 'is_active' => 'boolean'];
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;

class AssessmentUnassessedReason extends Model
{
    use HasUuidPrimaryKey;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['requires_approval' => 'bool', 'excludes_from_denominator' => 'bool', 'is_system' => 'bool', 'is_active' => 'bool'];
    }
}

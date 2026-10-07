<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;

/**
 * Assessment type: reference data, editable (behavioural, technical, leadership …).
 */
class AssessmentType extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'assessment_types';

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
}

<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A qualification an activity or task needs, as typed columns (brief §34). */
class WorkQualificationRequirement extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = ['organization_id', 'activity_id', 'task_id', 'education_level_id', 'field_of_study', 'certification', 'license', 'experience_years', 'is_mandatory', 'sequence'];

    protected function casts(): array
    {
        return ['experience_years' => 'integer', 'is_mandatory' => 'boolean', 'sequence' => 'integer'];
    }

    public function educationLevel(): BelongsTo
    {
        return $this->belongsTo(EducationLevel::class);
    }
}

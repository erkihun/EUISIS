<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A qualification a position needs, as typed columns (brief §70): derived from the linked work or written. */
class PositionQualificationRequirement extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = ['position_id', 'organization_id', 'education_level_id', 'field_of_study', 'certification', 'license', 'experience_years', 'is_mandatory', 'source', 'sequence'];

    protected function casts(): array
    {
        return ['experience_years' => 'integer', 'is_mandatory' => 'boolean', 'sequence' => 'integer'];
    }

    public function educationLevel(): BelongsTo
    {
        return $this->belongsTo(EducationLevel::class);
    }
}

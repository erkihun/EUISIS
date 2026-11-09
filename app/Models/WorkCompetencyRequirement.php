<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A competency an activity or task needs, at a minimum named level, mandatory or not (brief §31–33). */
class WorkCompetencyRequirement extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = ['organization_id', 'activity_id', 'task_id', 'competency_id', 'minimum_level_id', 'is_mandatory', 'sequence'];

    protected function casts(): array
    {
        return ['is_mandatory' => 'boolean', 'sequence' => 'integer'];
    }

    public function competency(): BelongsTo
    {
        return $this->belongsTo(Competency::class);
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(CompetencyLevel::class, 'minimum_level_id');
    }
}

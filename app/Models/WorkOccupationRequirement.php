<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Who may perform an activity or task: an occupation and, within it, a profession and specialization (brief §29). */
class WorkOccupationRequirement extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = ['organization_id', 'activity_id', 'task_id', 'occupation_id', 'profession_id', 'specialization_id', 'is_primary', 'sequence'];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean', 'sequence' => 'integer'];
    }

    public function occupation(): BelongsTo
    {
        return $this->belongsTo(Occupation::class);
    }

    public function profession(): BelongsTo
    {
        return $this->belongsTo(Profession::class);
    }

    public function specialization(): BelongsTo
    {
        return $this->belongsTo(Specialization::class);
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A competency a position needs at a minimum level (brief §69): derived from the linked work or written. */
class PositionCompetencyRequirement extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = ['position_id', 'organization_id', 'competency_id', 'minimum_level_id', 'is_mandatory', 'source', 'sequence'];

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

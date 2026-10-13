<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Discipline\DisciplinaryFinding;
use App\Enums\Discipline\DisciplinaryPenalty;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Committee (or investigator) recommendation. The finding is stored apart
 * from the penalty; an adopted version is never edited — a re-examination
 * adds a new version.
 */
class DisciplinaryRecommendation extends Model
{
    use HasUuidPrimaryKey;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'finding' => DisciplinaryFinding::class,
            'recommended_penalty' => DisciplinaryPenalty::class,
            'tie_broken_by_chair' => 'boolean',
            'adopted_at' => 'datetime',
        ];
    }

    public function disciplinaryCase(): BelongsTo
    {
        return $this->belongsTo(DisciplinaryCase::class, 'case_id');
    }

    public function votes(): HasMany
    {
        return $this->hasMany(DisciplinaryRecommendationVote::class, 'recommendation_id');
    }
}

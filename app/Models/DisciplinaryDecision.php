<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Discipline\DisciplinaryFinding;
use App\Enums\Discipline\DisciplinaryHeadAction;
use App\Enums\Discipline\DisciplinaryPenalty;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * The head of institution's decision (Art. 28). It references, never
 * overwrites, the recommendation. A decision counts as delivered only once
 * delivered_at is recorded (Art. 28(3)); generating a letter is not delivery.
 */
class DisciplinaryDecision extends Model
{
    use HasUuidPrimaryKey;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'action' => DisciplinaryHeadAction::class,
            'finding' => DisciplinaryFinding::class,
            'penalty' => DisciplinaryPenalty::class,
            'decided_at' => 'datetime',
            'delivered_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'effective_on' => 'date',
        ];
    }

    public function disciplinaryCase(): BelongsTo
    {
        return $this->belongsTo(DisciplinaryCase::class, 'case_id');
    }

    /** The appeal to the Administrative Court against this decision, if any. */
    public function appeal(): HasOne
    {
        return $this->hasOne(DisciplinaryAppeal::class, 'decision_id');
    }

    public function recommendation(): BelongsTo
    {
        return $this->belongsTo(DisciplinaryRecommendation::class);
    }
}

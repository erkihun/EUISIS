<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Discipline\DisciplinaryAppealOutcome;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An appeal to the Administrative Court against a delivered disciplinary
 * decision (Regulation 201/2018 Part Five). Written only by
 * DisciplinaryCaseService. While it has no outcome the appeal is pending
 * and the decision's penalty is not executed (Art. 29).
 */
class DisciplinaryAppeal extends Model
{
    use HasUuidPrimaryKey;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'notice_received_at' => 'datetime',
            'record_copy_requested' => 'boolean',
            'record_copy_delivered_at' => 'datetime',
            'filed_on' => 'date',
            'outcome' => DisciplinaryAppealOutcome::class,
            'outcome_decided_on' => 'date',
            'outcome_received_at' => 'datetime',
            'implemented_at' => 'datetime',
        ];
    }

    public function isPending(): bool
    {
        return $this->outcome === null;
    }

    public function disciplinaryCase(): BelongsTo
    {
        return $this->belongsTo(DisciplinaryCase::class, 'case_id');
    }

    public function decision(): BelongsTo
    {
        return $this->belongsTo(DisciplinaryDecision::class, 'decision_id');
    }
}

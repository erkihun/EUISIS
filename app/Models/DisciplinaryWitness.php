<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Discipline\DisciplinaryEvidenceSide;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Witness record with the particulars Art. 24(4) requires the committee to register. */
class DisciplinaryWitness extends Model
{
    use HasUuidPrimaryKey;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'called_by' => DisciplinaryEvidenceSide::class,
            'written_testimony' => 'boolean',
            'heard_in_absence_of_other_party' => 'boolean',
            'heard_at' => 'datetime',
        ];
    }

    public function disciplinaryCase(): BelongsTo
    {
        return $this->belongsTo(DisciplinaryCase::class, 'case_id');
    }
}

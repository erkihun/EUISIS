<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Discipline\DisciplinaryFinding;
use App\Enums\Discipline\DisciplinaryPlea;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One alleged violation, stated separately as Art. 15(2) requires. */
class DisciplinaryCharge extends Model
{
    use HasUuidPrimaryKey;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'occurred_on' => 'date:Y-m-d',
            'plea' => DisciplinaryPlea::class,
            'finding' => DisciplinaryFinding::class,
        ];
    }

    public function disciplinaryCase(): BelongsTo
    {
        return $this->belongsTo(DisciplinaryCase::class, 'case_id');
    }
}

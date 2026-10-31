<?php

declare(strict_types=1);

namespace Modules\Discipline\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Discipline\Contracts\Data\DisciplinaryFinding;
use Modules\Discipline\Domain\Enums\DisciplinaryPlea;

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

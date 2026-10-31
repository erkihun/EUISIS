<?php

declare(strict_types=1);

namespace Modules\Discipline\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Discipline\Domain\Enums\DisciplinaryEvidenceSide;

/** Documentary evidence. Files sit on the private disk and leave only through the audited download. */
class DisciplinaryEvidence extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'disciplinary_evidence';

    protected $guarded = ['*'];

    protected $hidden = ['disk', 'path'];

    protected function casts(): array
    {
        return [
            'side' => DisciplinaryEvidenceSide::class,
            'size_bytes' => 'integer',
        ];
    }

    public function disciplinaryCase(): BelongsTo
    {
        return $this->belongsTo(DisciplinaryCase::class, 'case_id');
    }
}

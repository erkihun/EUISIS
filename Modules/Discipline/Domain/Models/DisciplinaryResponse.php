<?php

declare(strict_types=1);

namespace Modules\Discipline\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Discipline\Domain\Enums\DisciplinaryObjectionGround;

/**
 * The accused's defence (Art. 20): preliminary objection and answer filed
 * together. An amendment (Art. 16) adds a new version; earlier ones stay.
 */
class DisciplinaryResponse extends Model
{
    use HasUuidPrimaryKey;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'objection_ground' => DisciplinaryObjectionGround::class,
            'objection_decided_at' => 'datetime',
            'received_at' => 'datetime',
            'pleas' => 'array',
        ];
    }

    public function disciplinaryCase(): BelongsTo
    {
        return $this->belongsTo(DisciplinaryCase::class, 'case_id');
    }
}

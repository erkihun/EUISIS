<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PositionCompetency extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'position_competencies';

    protected $fillable = [
        'position_id',
        'competency_id',
        'weight',
    ];

    protected function casts(): array
    {
        return [
            'weight' => 'decimal:4',
        ];
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function competency(): BelongsTo
    {
        return $this->belongsTo(Competency::class);
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The frozen picture of an organization at the moment its baseline was
 * published: units, their relationships, positions, establishments, counts
 * per stage of the chain and the ids of the records that made it up. Never
 * updated; the organogram history and version comparison read it.
 */
class LegacyBaselineSnapshot extends Model
{
    use HasUuidPrimaryKey;

    public $timestamps = false;

    protected $fillable = ['baseline_id', 'hierarchy_version_id', 'taken_at', 'counts', 'units', 'relationships', 'positions', 'establishments', 'chain', 'sha256'];

    protected function casts(): array
    {
        return [
            'taken_at' => 'datetime',
            'counts' => 'array',
            'units' => 'array',
            'relationships' => 'array',
            'positions' => 'array',
            'establishments' => 'array',
            'chain' => 'array',
        ];
    }

    public function baseline(): BelongsTo
    {
        return $this->belongsTo(LegacyBaseline::class, 'baseline_id');
    }
}

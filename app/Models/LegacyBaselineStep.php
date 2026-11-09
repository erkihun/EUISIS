<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One step of the legacy capture wizard. `record_count` is detected from
 * the design records of the organization; `status` is derived from it
 * unless a person set it (`status_source` manual) with a note explaining
 * why, which is how "this institution has no lower offices" is recorded.
 */
class LegacyBaselineStep extends Model
{
    use HasUuidPrimaryKey;

    public $timestamps = false;

    public const STATUSES = ['complete', 'incomplete', 'needs_review', 'error'];

    protected $fillable = ['baseline_id', 'step_key', 'status', 'status_source', 'record_count', 'notes', 'updated_by', 'updated_at'];

    protected function casts(): array
    {
        return ['record_count' => 'integer', 'updated_at' => 'datetime'];
    }

    public function baseline(): BelongsTo
    {
        return $this->belongsTo(LegacyBaseline::class, 'baseline_id');
    }
}

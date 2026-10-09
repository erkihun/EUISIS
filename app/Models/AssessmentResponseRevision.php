<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A previously submitted version of a response, kept when it was returned for correction. */
class AssessmentResponseRevision extends Model
{
    use HasUuidPrimaryKey;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['items' => 'array', 'score_snapshot' => 'array', 'submitted_at' => 'datetime', 'returned_at' => 'datetime', 'created_at' => 'datetime'];
    }

    public function response(): BelongsTo { return $this->belongsTo(AssessmentResponse::class, 'response_id'); }
}

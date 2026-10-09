<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One criterion answer of one evaluator. The score is resolved by the server, never trusted from the browser. */
class AssessmentResponseItem extends Model
{
    use HasUuidPrimaryKey;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['score_snapshot' => 'decimal:4'];
    }

    public function response(): BelongsTo { return $this->belongsTo(AssessmentResponse::class, 'response_id'); }
    public function criterion(): BelongsTo { return $this->belongsTo(AssessmentCriterion::class, 'criterion_id'); }
    public function option(): BelongsTo { return $this->belongsTo(AssessmentRatingOption::class, 'rating_option_id'); }
}

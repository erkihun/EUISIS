<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Supporting file for a criterion; stored on a private disk and served only through an authorized route. */
class AssessmentResponseEvidence extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'assessment_response_evidence';

    protected $guarded = ['id'];

    public function response(): BelongsTo { return $this->belongsTo(AssessmentResponse::class, 'response_id'); }
}

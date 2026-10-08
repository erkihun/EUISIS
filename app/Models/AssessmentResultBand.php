<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssessmentResultBand extends Model
{
    use HasUuidPrimaryKey;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['min_score' => 'decimal:4', 'max_score' => 'decimal:4', 'min_inclusive' => 'bool', 'max_inclusive' => 'bool'];
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(AssessmentResultBandPolicy::class, 'policy_id');
    }
}

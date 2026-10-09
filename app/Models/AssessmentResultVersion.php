<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssessmentResultVersion extends Model
{
    use HasUuidPrimaryKey;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['raw_score' => 'decimal:4', 'normalized_score' => 'decimal:4', 'contribution_score' => 'decimal:4', 'effective_at' => 'datetime', 'is_current' => 'bool'];
    }

    public function record(): BelongsTo { return $this->belongsTo(AssessmentRecord::class, 'assessment_record_id'); }
    public function supersedes(): BelongsTo { return $this->belongsTo(self::class, 'supersedes_version_id'); }
}

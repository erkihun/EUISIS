<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Assessment\TargetType;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Which employees a form version applies to (AssessmentTargetResolver).
 */
class AssessmentTargetRule extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'assessment_form_target_rules';

    protected $fillable = [
        'form_version_id',
        'target_type',
        'target_id',
        'target_value',
        'include_descendants',
        'effect',
        'priority',
        'effective_from',
        'effective_to',
    ];

    protected function casts(): array
    {
        return [
            'target_type' => TargetType::class,
            'include_descendants' => 'bool',
            'priority' => 'integer',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(AssessmentFormVersion::class, 'form_version_id');
    }

    public function isExclude(): bool
    {
        return $this->effect === 'exclude';
    }
}

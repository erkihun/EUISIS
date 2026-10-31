<?php

declare(strict_types=1);

namespace Modules\Assessment\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\User;

/** Audit trail of evaluator assignment, reassignment and recusal for a record. */
class AssessmentEvaluatorChange extends Model
{
    use HasUuidPrimaryKey;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(AssessmentRecord::class, 'assessment_record_id');
    }

    public function changer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}

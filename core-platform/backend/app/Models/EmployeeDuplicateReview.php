<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A pair of employee records that may be one person (ADR 0009): the flagged
 * record (`employee_id`), the record it matched, why, and the reviewer's
 * decision. A confirmed duplicate blocks the flagged record until a
 * controlled merge; nothing is merged automatically.
 */
class EmployeeDuplicateReview extends Model
{
    use HasUuids;

    public const PENDING = 'pending';

    public const NOT_DUPLICATE = 'not_duplicate';

    public const CONFIRMED = 'confirmed_duplicate';

    public const NEEDS_INFORMATION = 'needs_more_information';

    public const DECISIONS = [self::NOT_DUPLICATE, self::CONFIRMED, self::NEEDS_INFORMATION];

    /** Decisions that leave the question open. */
    public const OPEN = [self::PENDING, self::NEEDS_INFORMATION];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['reasons' => 'array', 'decided_at' => 'datetime', 'detected_at' => 'datetime'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function matchedEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'matched_employee_id');
    }
}

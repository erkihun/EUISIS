<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One finding of a data-quality check (ADR 0011): open → investigating →
 * resolved, accepted risk or false positive. The scan reopens a resolved
 * finding it sees again; an accepted risk or a false positive stays closed.
 */
class DataQualityIssue extends Model
{
    use HasUuids;

    public const OPEN = 'open';

    public const INVESTIGATING = 'investigating';

    public const RESOLVED = 'resolved';

    public const ACCEPTED_RISK = 'accepted_risk';

    public const FALSE_POSITIVE = 'false_positive';

    public const STATUSES = [self::OPEN, self::INVESTIGATING, self::RESOLVED, self::ACCEPTED_RISK, self::FALSE_POSITIVE];

    /** Still needing someone's attention. */
    public const ACTIVE = [self::OPEN, self::INVESTIGATING];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'details' => 'array', 'detected_at' => 'datetime', 'last_detected_at' => 'datetime', 'resolved_at' => 'datetime',
            'accepted_risk_at' => 'datetime', 'auto_resolved' => 'boolean', 'times_reopened' => 'integer',
        ];
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}

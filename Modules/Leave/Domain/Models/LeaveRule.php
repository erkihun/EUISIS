<?php

declare(strict_types=1);

namespace Modules\Leave\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The figures of one rule set for one leave type. */
class LeaveRule extends Model
{
    use HasUuidPrimaryKey;

    public const CARRY_NONE = 'none';

    public const CARRY_AUTOMATIC = 'automatic';

    public const CARRY_AUTHORIZED = 'authorized';

    protected $fillable = [
        'leave_rule_set_id', 'leave_type_id', 'base_days', 'increment_per_service_year', 'max_days', 'waiting_months',
        'carry_over_mode', 'carry_over_years', 'max_days_per_request', 'max_days_per_year', 'settings',
    ];

    protected function casts(): array
    {
        return [
            'base_days' => 'float',
            'increment_per_service_year' => 'float',
            'max_days' => 'float',
            'waiting_months' => 'integer',
            'carry_over_years' => 'integer',
            'max_days_per_request' => 'float',
            'max_days_per_year' => 'float',
            'settings' => 'array',
        ];
    }

    public function ruleSet(): BelongsTo
    {
        return $this->belongsTo(LeaveRuleSet::class, 'leave_rule_set_id');
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    /** A whole number from the type-specific figures, or null when the institution has not set it. */
    public function setting(string $key): ?int
    {
        $value = $this->settings[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }
}

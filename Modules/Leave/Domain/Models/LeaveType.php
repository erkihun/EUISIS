<?php

declare(strict_types=1);

namespace Modules\Leave\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Leave\Domain\Enums\LeaveCategory;

/**
 * A kind of leave an institution grants. The category carries the law; the
 * names and the switches are the institution's.
 */
class LeaveType extends Model
{
    use HasUuidPrimaryKey;

    public const UNIT_WORKING = 'working';

    public const UNIT_CALENDAR = 'calendar';

    public const PAY_FULL = 'full';

    public const PAY_NONE = 'none';

    public const PAY_BY_PERIOD = 'by_period';

    protected $fillable = [
        'code', 'name_en', 'name_am', 'category', 'day_unit', 'pay_class', 'uses_balance', 'allows_half_day', 'allows_past_start',
        'requires_supervisor_approval', 'requires_document', 'employee_can_request', 'gender', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'category' => LeaveCategory::class,
            'uses_balance' => 'bool',
            'allows_half_day' => 'bool',
            'allows_past_start' => 'bool',
            'requires_supervisor_approval' => 'bool',
            'requires_document' => 'bool',
            'employee_can_request' => 'bool',
            'is_active' => 'bool',
            'is_system' => 'bool',
            'sort_order' => 'integer',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function countsWorkingDays(): bool
    {
        return $this->day_unit === self::UNIT_WORKING;
    }

    /** Whether an employee of this gender may take the type (a type without a gender is open to all). */
    public function openTo(?string $gender): bool
    {
        return $this->gender === null || $this->gender === '' || strtolower((string) $gender) === $this->gender;
    }
}

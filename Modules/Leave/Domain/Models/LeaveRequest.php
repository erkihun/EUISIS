<?php

declare(strict_types=1);

namespace Modules\Leave\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\OrganizationUnit;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Leave\Contracts\Data\LeaveStatus;

/**
 * One leave request.
 *
 * Only the fields an employee writes are mass-assignable. The employee, the
 * placement snapshot, the counted days, the status and every decision are
 * written by LeaveRequestService with forceFill from server-side data, so a
 * payload that smuggles `status` or `employee_id` has nothing to bind to.
 */
class LeaveRequest extends Model
{
    use HasUuidPrimaryKey;

    public const HALF_MORNING = 'morning';

    public const HALF_AFTERNOON = 'afternoon';

    protected $fillable = ['reason', 'contact_during_leave'];

    protected function casts(): array
    {
        return [
            'status' => LeaveStatus::class,
            'starts_on' => 'date:Y-m-d',
            'ends_on' => 'date:Y-m-d',
            'expected_delivery_on' => 'date:Y-m-d',
            'actual_delivery_on' => 'date:Y-m-d',
            'ended_early_on' => 'date:Y-m-d',
            'is_half_day' => 'bool',
            'registered_by_hr' => 'bool',
            'days_counted' => 'float',
            'budget_year' => 'integer',
            'pay_breakdown' => 'array',
            'submitted_at' => 'datetime',
            'supervisor_decided_at' => 'datetime',
            'hr_decided_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function ruleSet(): BelongsTo
    {
        return $this->belongsTo(LeaveRuleSet::class, 'leave_rule_set_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function organizationUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationUnit::class);
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_user_id');
    }

    public function days(): HasMany
    {
        return $this->hasMany(LeaveRequestDay::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(LeaveRequestEvent::class)->orderBy('created_at')->orderBy('id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(LeaveDocument::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LeaveLedgerEntry::class);
    }
}

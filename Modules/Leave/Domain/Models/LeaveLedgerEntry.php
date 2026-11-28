<?php

declare(strict_types=1);

namespace Modules\Leave\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Leave\Domain\Enums\LeaveEntryType;

/**
 * One line of an employee's leave ledger. A balance is the sum of the lines
 * of one employee, leave type and entitlement year; nothing is edited in
 * place, a correction is a new line.
 */
class LeaveLedgerEntry extends Model
{
    use HasUuidPrimaryKey;

    public $timestamps = false;

    protected $fillable = [
        'employee_id', 'leave_type_id', 'entitlement_year', 'entry_type', 'days', 'usable_until_year', 'leave_request_id',
        'leave_rule_set_id', 'organization_id', 'note', 'created_by', 'unique_key', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'entry_type' => LeaveEntryType::class,
            'days' => 'float',
            'entitlement_year' => 'integer',
            'usable_until_year' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class, 'leave_request_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

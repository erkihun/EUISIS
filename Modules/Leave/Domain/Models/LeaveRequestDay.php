<?php

declare(strict_types=1);

namespace Modules\Leave\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One day of a request that still holds its dates. The rows answer two
 * questions cheaply: does a new request overlap, and was the employee on
 * approved full-day leave on a date (EmployeeLeaveProvider).
 */
class LeaveRequestDay extends Model
{
    use HasUuidPrimaryKey;

    public $timestamps = false;

    protected $fillable = ['leave_request_id', 'employee_id', 'leave_date', 'portion', 'is_full_day', 'is_counted', 'is_approved'];

    protected function casts(): array
    {
        return [
            'leave_date' => 'date:Y-m-d',
            'portion' => 'float',
            'is_full_day' => 'bool',
            'is_counted' => 'bool',
            'is_approved' => 'bool',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class, 'leave_request_id');
    }
}

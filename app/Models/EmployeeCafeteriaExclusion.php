<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CafeteriaExclusionStatus;
use App\Enums\CafeteriaExclusionType;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class EmployeeCafeteriaExclusion extends Model
{
    use HasUuidPrimaryKey;
    use SoftDeletes;

    protected $fillable = [
        'employee_id',
        'exclusion_type',
        'starts_on',
        'ends_on',
        'return_to_work_on',
        'is_open_ended',
        'reason_en',
        'reason_am',
        'status',
        'created_by',
        'updated_by',
        'ended_by',
        'ended_at',
        'deleted_by',
        'deletion_reason',
    ];

    protected $casts = [
        'exclusion_type'   => CafeteriaExclusionType::class,
        'starts_on'        => 'date',
        'ends_on'          => 'date',
        'return_to_work_on' => 'date',
        'is_open_ended'    => 'boolean',
        'status'           => CafeteriaExclusionStatus::class,
        'ended_at'         => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function endedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ended_by');
    }

    public function isActiveOn(Carbon $date): bool
    {
        if ($this->status !== CafeteriaExclusionStatus::Active) {
            return false;
        }

        if ($this->starts_on->gt($date)) {
            return false;
        }

        if ($this->ends_on && $this->ends_on->lt($date)) {
            return false;
        }

        if ($this->return_to_work_on && $this->return_to_work_on->lte($date)) {
            return false;
        }

        return true;
    }
}

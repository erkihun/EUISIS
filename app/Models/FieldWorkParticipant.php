<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class FieldWorkParticipant extends Model
{
    use HasUuidPrimaryKey;

    protected $guarded = ['id', 'field_work_request_id', 'employee_id', 'employee_assignment_id', 'organization_id', 'organization_unit_id', 'position_id'];

    protected function casts(): array
    {
        return ['is_primary_requester' => 'bool'];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(FieldWorkRequest::class, 'field_work_request_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(EmployeeAssignment::class, 'employee_assignment_id');
    }

    public function locationEvents(): HasMany
    {
        return $this->hasMany(FieldWorkLocationEvent::class);
    }

    public function session(): HasOne
    {
        return $this->hasOne(FieldWorkSession::class);
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FieldWorkDestinationType;
use App\Enums\FieldWorkStatus;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A temporary work-location authorization. It never creates an assignment. */
class FieldWorkRequest extends Model
{
    use HasUuidPrimaryKey;

    protected $guarded = ['id', 'reference_number', 'requester_employee_id', 'requester_assignment_id', 'organization_id', 'organization_unit_id', 'position_id', 'supervisor_user_id', 'supervisor_employee_id', 'supervisor_name_snapshot', 'supervisor_employee_number_snapshot', 'approved_by_employee_id', 'approved_at', 'returned_by_employee_id', 'returned_at', 'rejected_by_employee_id', 'rejected_at', 'completed_by_employee_id', 'completed_at', 'created_by'];

    protected function casts(): array
    {
        return ['destination_type' => FieldWorkDestinationType::class, 'status' => FieldWorkStatus::class, 'starts_at' => 'datetime', 'expected_return_at' => 'datetime', 'actual_departure_at' => 'datetime', 'actual_return_at' => 'datetime', 'submitted_at' => 'datetime', 'approved_at' => 'datetime', 'returned_at' => 'datetime', 'rejected_at' => 'datetime', 'cancelled_at' => 'datetime', 'completed_at' => 'datetime', 'is_full_day' => 'bool', 'is_multi_day' => 'bool'];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'requester_employee_id');
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(EmployeeAssignment::class, 'requester_assignment_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function organizationUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationUnit::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function fieldWorkType(): BelongsTo
    {
        return $this->belongsTo(FieldWorkType::class);
    }

    public function destinationOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'destination_organization_id');
    }

    public function destinationOrganizationUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationUnit::class, 'destination_organization_unit_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'approved_by_employee_id');
    }

    public function supervisorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_user_id');
    }

    public function supervisorEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'supervisor_employee_id');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(FieldWorkParticipant::class);
    }

    public function locationEvents(): HasMany
    {
        return $this->hasMany(FieldWorkLocationEvent::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(FieldWorkSession::class);
    }
}

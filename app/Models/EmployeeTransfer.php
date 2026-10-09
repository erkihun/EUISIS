<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TransferStatus;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeTransfer extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'employee_id',
        'from_organization_id',
        'to_organization_id',
        'from_organization_unit_id',
        'to_organization_unit_id',
        'from_position_id',
        'to_position_id',
        'current_assignment_id',
        'requested_by',
        'current_org_confirmed_by',
        'receiving_organization_confirmed_by',
        'approved_by',
        'rejected_by',
        'transfer_reason',
        'rejection_reason',
        'effective_date',
        'status',
        'submitted_at',
        'current_org_confirmed_at',
        'receiving_org_confirmed_at',
        'approved_at',
        'rejected_at',
        'completed_at',
        'metadata',
        'transfer_source',
        'vacancy_application_id',
        'vacancy_announcement_id',
        'transfer_application_id',
        'destination_assignment_id',
        'source_assignment_snapshot',
        'destination_assignment_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'effective_date' => 'date',
            'status' => TransferStatus::class,
            'submitted_at' => 'datetime',
            'current_org_confirmed_at' => 'datetime',
            'receiving_org_confirmed_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'completed_at' => 'datetime',
            'metadata' => 'array',
            'source_assignment_snapshot' => 'array',
            'destination_assignment_snapshot' => 'array',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function fromOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'from_organization_id');
    }

    public function toOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'to_organization_id');
    }

    public function fromOrganizationUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationUnit::class, 'from_organization_unit_id');
    }

    public function toOrganizationUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationUnit::class, 'to_organization_unit_id');
    }

    public function fromPosition(): BelongsTo
    {
        return $this->belongsTo(Position::class, 'from_position_id');
    }

    public function toPosition(): BelongsTo
    {
        return $this->belongsTo(Position::class, 'to_position_id');
    }

    public function currentAssignment(): BelongsTo
    {
        return $this->belongsTo(EmployeeAssignment::class, 'current_assignment_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function currentOrganizationConfirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'current_org_confirmed_by');
    }

    public function receivingOrganizationConfirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'receiving_organization_confirmed_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function vacancyApplication(): BelongsTo
    {
        return $this->belongsTo(VacancyApplication::class, 'vacancy_application_id');
    }

    public function vacancyAnnouncement(): BelongsTo
    {
        return $this->belongsTo(VacancyAnnouncement::class, 'vacancy_announcement_id');
    }
}

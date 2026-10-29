<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AssignmentStatus;
use App\Models\Concerns\HasIntegrationEvents;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeAssignment extends Model
{
    use HasIntegrationEvents;
    use HasUuidPrimaryKey;

    protected $fillable = [
        'employee_id',
        'organization_id',
        'organization_unit_id',
        'position_id',
        'job_description_id',
        'hierarchy_version_id',
        'assignment_status',
        'effective_from',
        'effective_to',
        'is_current',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'assignment_status' => AssignmentStatus::class,
            'effective_from' => 'date:Y-m-d',
            'effective_to' => 'date:Y-m-d',
            'is_current' => 'bool',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    /** The job description in force when the person was assigned (brief §66). */
    public function jobDescription(): BelongsTo
    {
        return $this->belongsTo(JobDescription::class, 'job_description_id');
    }

    public function hierarchyVersion(): BelongsTo
    {
        return $this->belongsTo(HierarchyVersion::class);
    }

    public function organizationUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationUnit::class);
    }
}

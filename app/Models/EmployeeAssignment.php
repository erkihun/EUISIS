<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AssignmentStatus;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeAssignment extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'employee_id',
        'organization_id',
        'organization_unit_id',
        'position_id',
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
            'effective_from' => 'date',
            'effective_to' => 'date',
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

    public function hierarchyVersion(): BelongsTo
    {
        return $this->belongsTo(HierarchyVersion::class);
    }

    public function organizationUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationUnit::class);
    }
}

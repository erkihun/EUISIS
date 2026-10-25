<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeAssignment extends Model
{
    use HasUuids;

    public const ACTIVE = 'active';

    /** The first placement of an employee not yet approved: it holds no seat until approval activates it. */
    public const PENDING = 'pending';

    public const CLOSED = 'closed';

    /** How a placement came about (ADR 0010). 'unspecified' only for rows older than P3. */
    public const TYPES = ['initial', 'transfer', 'promotion', 'demotion', 'reassignment', 'correction'];

    /** Why, as a code reports can count; the free-text reason says the rest. */
    public const REASON_CODES = ['registration', 'reorganization', 'promotion', 'demotion', 'lateral_move', 'employee_request', 'disciplinary', 'correction', 'other'];

    /** Where the placement was recorded: these screens, the API, a business import, or before P3. */
    public const SOURCES = ['core', 'api', 'import', 'legacy'];

    protected $guarded = ['id', 'current_employee_id'];

    protected function casts(): array
    {
        return ['is_current' => 'boolean', 'is_primary' => 'boolean', 'effective_from' => 'date', 'effective_to' => 'date', 'approved_at' => 'datetime'];
    }

    public function isEffective(): bool
    {
        return $this->assignment_status === self::ACTIVE
            && $this->is_current
            && ($this->effective_from === null || $this->effective_from->lte(today()))
            && ($this->effective_to === null || $this->effective_to->gte(today()));
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(OrganizationUnit::class, 'organization_unit_id');
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }
}

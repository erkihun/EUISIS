<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FieldWorkDestinationType;
use App\Enums\FieldWorkMonitoringFlag;
use App\Enums\FieldWorkScheduleType;
use App\Enums\FieldWorkStatus;
use App\Enums\FieldWorkSupervisorResolution;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One field work request.
 *
 * Only descriptive fields are mass-assignable. Status, requester, the
 * placement snapshot, supervisor and every workflow timestamp are written by
 * FieldWorkService with forceFill from server-side data, so a payload that
 * smuggles `status`, `requester_employee_id` or `supervisor_user_id` has
 * nothing to bind to.
 */
class FieldWorkRequest extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'field_work_type_id',
        'purpose',
        'activity_description',
        'destination_type',
        'destination_organization_id',
        'destination_organization_unit_id',
        'external_organization_name',
        'site_name',
        'destination_address',
        'contact_person',
        'contact_phone',
        'expected_latitude',
        'expected_longitude',
        'geofence_radius_m',
        'starts_at',
        'expected_return_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => FieldWorkStatus::class,
            'destination_type' => FieldWorkDestinationType::class,
            'schedule_type' => FieldWorkScheduleType::class,
            'supervisor_resolution' => FieldWorkSupervisorResolution::class,
            'context_snapshot' => 'array',
            'is_team' => 'bool',
            'follow_up_required' => 'bool',
            'expected_latitude' => 'float',
            'expected_longitude' => 'float',
            'geofence_radius_m' => 'integer',
            'submission_count' => 'integer',
            'starts_at' => 'datetime',
            'expected_return_at' => 'datetime',
            'submitted_at' => 'datetime',
            'decided_at' => 'datetime',
            'actual_start_at' => 'datetime',
            'actual_return_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'requester_employee_id');
    }

    public function requesterUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_user_id');
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(EmployeeAssignment::class, 'employee_assignment_id');
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

    public function type(): BelongsTo
    {
        return $this->belongsTo(FieldWorkType::class, 'field_work_type_id');
    }

    public function destinationOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'destination_organization_id');
    }

    public function destinationOrganizationUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationUnit::class, 'destination_organization_unit_id');
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_user_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(FieldWorkParticipant::class)->orderBy('role')->orderBy('created_at');
    }

    public function locationEvents(): HasMany
    {
        return $this->hasMany(FieldWorkLocationEvent::class)->orderBy('captured_at');
    }

    public function histories(): HasMany
    {
        return $this->hasMany(FieldWorkHistory::class)->orderBy('created_at');
    }

    public function hasGeofence(): bool
    {
        return $this->expected_latitude !== null && $this->expected_longitude !== null && $this->geofence_radius_m !== null;
    }

    /** Derived, never stored; see FieldWorkMonitoringFlag. */
    public function monitoringFlag(?Carbon $now = null): ?FieldWorkMonitoringFlag
    {
        $now ??= now();

        if (! $this->status->isOpen()) {
            return null;
        }
        if ($this->expected_return_at->lessThan($now)) {
            return FieldWorkMonitoringFlag::Overdue;
        }
        if ($this->status === FieldWorkStatus::Approved && $this->starts_at->lessThan($now)) {
            return FieldWorkMonitoringFlag::CheckInMissing;
        }

        return null;
    }

    /** Requests whose [starts_at, expected_return_at] window overlaps [from, to]. */
    public function scopeOverlapping(Builder $query, Carbon $from, Carbon $to): Builder
    {
        return $query->where($query->qualifyColumn('starts_at'), '<', $to)
            ->where($query->qualifyColumn('expected_return_at'), '>', $from);
    }

    /** Open (approved / in field) work past its expected return: overdue / not closed. */
    public function scopeOverdue(Builder $query, ?Carbon $now = null): Builder
    {
        return $query->whereIn($query->qualifyColumn('status'), FieldWorkStatus::openValues())
            ->where($query->qualifyColumn('expected_return_at'), '<', $now ?? now());
    }

    public function scopeCheckInMissing(Builder $query, ?Carbon $now = null): Builder
    {
        $now ??= now();

        return $query->where($query->qualifyColumn('status'), FieldWorkStatus::Approved->value)
            ->where($query->qualifyColumn('starts_at'), '<', $now)
            ->where($query->qualifyColumn('expected_return_at'), '>=', $now);
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\Performance\AgreementStatus;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The agreed plan for one employee, assignment and cycle. Context (organization, unit, position) is a snapshot so transfers never rewrite history.
 */
class EmployeePerformanceAgreement extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'employee_performance_agreements';

    protected $fillable = [
        'cycle_id',
        'employee_id',
        'employee_assignment_id',
        'performance_plan_id',
        'organization_id',
        'organization_unit_id',
        'position_id',
        'manager_user_id',
        'is_temporary',
        'effective_from',
        'effective_to',
    ];

    protected function casts(): array
    {
        return [
            'status' => AgreementStatus::class,
            'effective_from' => DateOnly::class,
            'effective_to' => DateOnly::class,
            'is_temporary' => 'boolean',
            'submitted_at' => 'datetime',
            'employee_acknowledged_at' => 'datetime',
            'manager_approved_at' => 'datetime',
            'approved_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(PerformanceCycle::class, 'cycle_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(EmployeeAssignment::class, 'employee_assignment_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(PerformancePlan::class, 'performance_plan_id');
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

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(EmployeePerformanceItem::class, 'agreement_id')->where('is_current', true)->orderBy('sort_order');
    }

    public function allItems(): HasMany
    {
        return $this->hasMany(EmployeePerformanceItem::class, 'agreement_id');
    }

    public function checkins(): HasMany
    {
        return $this->hasMany(PerformanceCheckin::class, 'agreement_id')->orderByDesc('checkin_date');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(PerformanceReview::class, 'agreement_id');
    }

    public function competencyAssessments(): HasMany
    {
        return $this->hasMany(EmployeeCompetencyAssessment::class, 'agreement_id');
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(PerformanceEvidence::class, 'agreement_id');
    }

    public function results(): HasMany
    {
        return $this->hasMany(PerformanceResult::class, 'agreement_id')->orderByDesc('revision_no');
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrganizationDesignStatus;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** An organization's manpower plan for a period (brief §49–56): lines, roll-up, approval, implementation. */
class ManpowerPlan extends Model
{
    use HasUuidPrimaryKey;
    use SoftDeletes;

    protected $fillable = [
        'reference_number', 'organization_id', 'hierarchy_version_id', 'study_id', 'methodology_id', 'title_en', 'title_am', 'notes',
        'planning_period_start', 'planning_period_end', 'status', 'prepared_by', 'submitted_at', 'reviewed_by', 'reviewed_at', 'review_comment',
        'approved_by', 'approved_at', 'implemented_at', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => OrganizationDesignStatus::class,
            'planning_period_start' => 'date:Y-m-d',
            'planning_period_end' => 'date:Y-m-d',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'approved_at' => 'datetime',
            'implemented_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function methodology(): BelongsTo
    {
        return $this->belongsTo(WorkforcePlanningMethodology::class, 'methodology_id');
    }

    public function study(): BelongsTo
    {
        return $this->belongsTo(OrganizationDesignStudy::class, 'study_id');
    }

    public function hierarchyVersion(): BelongsTo
    {
        return $this->belongsTo(HierarchyVersion::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ManpowerPlanLine::class, 'plan_id')->orderBy('sequence')->orderBy('created_at');
    }

    public function requirements(): HasMany
    {
        return $this->hasMany(PositionRequirement::class, 'plan_id')->orderBy('sequence');
    }

    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isEditable(): bool
    {
        return $this->status === OrganizationDesignStatus::Draft;
    }
}

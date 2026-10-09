<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Performance\GoalAllocationType;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StrategicGoalAllocation extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = ['strategic_goal_id', 'organization_unit_id', 'organization_contribution_percent', 'allocation_type', 'is_lead', 'notes', 'created_by'];

    protected function casts(): array
    {
        return ['allocation_type' => GoalAllocationType::class, 'organization_contribution_percent' => 'decimal:4', 'is_lead' => 'boolean'];
    }

    public function goal(): BelongsTo
    {
        return $this->belongsTo(StrategicGoal::class, 'strategic_goal_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(OrganizationUnit::class, 'organization_unit_id');
    }
}

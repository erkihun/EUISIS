<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\Performance\StrategicGoalStatus;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StrategicGoal extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = ['cycle_id', 'organization_id', 'code', 'name_am', 'name_en', 'description_am', 'description_en', 'weight_percent', 'is_shared', 'sort_order', 'effective_from', 'effective_to'];

    protected function casts(): array
    {
        return ['status' => StrategicGoalStatus::class, 'weight_percent' => 'decimal:4', 'is_shared' => 'boolean', 'effective_from' => DateOnly::class, 'effective_to' => DateOnly::class, 'published_at' => 'datetime'];
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(PerformanceCycle::class, 'cycle_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(StrategicGoalAllocation::class);
    }

    public function objectives(): HasMany
    {
        return $this->hasMany(PerformanceObjective::class);
    }
}

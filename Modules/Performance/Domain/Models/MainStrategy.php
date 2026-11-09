<?php

declare(strict_types=1);

namespace Modules\Performance\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A main strategy of one organization in one cycle (ADR 0019). Its weight is
 * percentage points of the whole plan; its strategies share exactly that weight.
 */
class MainStrategy extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'performance_main_strategies';

    protected $fillable = ['cycle_id', 'organization_id', 'title', 'description', 'weight_percent', 'sort_order', 'created_by'];

    protected function casts(): array
    {
        return ['weight_percent' => 'decimal:4', 'sort_order' => 'integer'];
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(PerformanceCycle::class, 'cycle_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function strategies(): HasMany
    {
        return $this->hasMany(Strategy::class, 'main_strategy_id')->orderBy('sort_order');
    }
}

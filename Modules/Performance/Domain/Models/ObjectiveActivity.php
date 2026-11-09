<?php

declare(strict_types=1);

namespace Modules\Performance\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A main or detailed activity of an objective; an objective's activities share 100% (ADR 0019). */
class ObjectiveActivity extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'performance_objective_activities';

    protected $fillable = ['objective_id', 'title', 'weight_percent', 'time_minutes', 'sort_order'];

    protected function casts(): array
    {
        return ['weight_percent' => 'decimal:4', 'time_minutes' => 'integer', 'sort_order' => 'integer'];
    }

    public function objective(): BelongsTo
    {
        return $this->belongsTo(PerformanceObjective::class, 'objective_id');
    }
}

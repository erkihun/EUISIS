<?php

declare(strict_types=1);

namespace Modules\Performance\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A strategy under a main strategy; the strategic goal registered for it takes its weight (ADR 0019). */
class Strategy extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'performance_strategies';

    protected $fillable = ['main_strategy_id', 'title', 'description', 'weight_percent', 'sort_order'];

    protected function casts(): array
    {
        return ['weight_percent' => 'decimal:4', 'sort_order' => 'integer'];
    }

    public function mainStrategy(): BelongsTo
    {
        return $this->belongsTo(MainStrategy::class, 'main_strategy_id');
    }

    /** Every version of the goal registered for this strategy. */
    public function goals(): HasMany
    {
        return $this->hasMany(StrategicGoal::class, 'strategy_id');
    }
}

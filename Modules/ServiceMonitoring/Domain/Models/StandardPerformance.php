<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Database\Factories\ServiceMonitoring\StandardPerformanceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Section IV: services delivered above, at and below standard (D21). */
class StandardPerformance extends Model
{
    /** @use HasFactory<StandardPerformanceFactory> */
    use HasFactory;

    use HasUuidPrimaryKey;

    protected $table = 'service_monitoring_standard_performance';

    protected $fillable = [
        'submission_id',
        'main_above',
        'main_at',
        'main_below',
        'sub_above',
        'sub_at',
        'sub_below',
        'below_reasons',
        'actions_taken',
    ];

    protected function casts(): array
    {
        return [
            'main_above' => 'integer',
            'main_at' => 'integer',
            'main_below' => 'integer',
            'sub_above' => 'integer',
            'sub_at' => 'integer',
            'sub_below' => 'integer',
        ];
    }

    protected static function newFactory(): StandardPerformanceFactory
    {
        return StandardPerformanceFactory::new();
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }
}

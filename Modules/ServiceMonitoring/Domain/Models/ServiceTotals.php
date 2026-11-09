<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Database\Factories\ServiceMonitoring\ServiceTotalsFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The institution's total main and sub services (stock, D4), shared by Sections III and IV. */
class ServiceTotals extends Model
{
    /** @use HasFactory<ServiceTotalsFactory> */
    use HasFactory;

    use HasUuidPrimaryKey;

    protected $table = 'service_monitoring_service_totals';

    protected $fillable = [
        'submission_id',
        'main_total',
        'sub_total',
        'catalog_main_total',
    ];

    protected function casts(): array
    {
        return [
            'main_total' => 'integer',
            'sub_total' => 'integer',
            'catalog_main_total' => 'integer',
        ];
    }

    protected static function newFactory(): ServiceTotalsFactory
    {
        return ServiceTotalsFactory::new();
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }
}

<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\Occupation;
use Database\Factories\ServiceMonitoring\DriverOccupationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An occupation whose holders are drivers: excluded from the Section II employee and chest-badge prefill (D18). */
class DriverOccupation extends Model
{
    /** @use HasFactory<DriverOccupationFactory> */
    use HasFactory;

    use HasUuidPrimaryKey;

    protected $table = 'service_monitoring_driver_occupations';

    protected $fillable = [
        'occupation_id',
        'created_by',
    ];

    protected static function newFactory(): DriverOccupationFactory
    {
        return DriverOccupationFactory::new();
    }

    public function occupation(): BelongsTo
    {
        return $this->belongsTo(Occupation::class);
    }
}

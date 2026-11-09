<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Database\Factories\ServiceMonitoring\FiscalYearFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\ServiceMonitoring\Domain\Enums\FiscalYearStatus;

/** An Ethiopian fiscal year (Hamle 1 to Sene 30, D2) and its four reporting periods. */
class FiscalYear extends Model
{
    /** @use HasFactory<FiscalYearFactory> */
    use HasFactory;

    use HasUuidPrimaryKey;

    protected $table = 'service_monitoring_fiscal_years';

    protected $fillable = [
        'ec_year',
        'starts_on',
        'ends_on',
        'status',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'ec_year' => 'integer',
            'starts_on' => 'date:Y-m-d',
            'ends_on' => 'date:Y-m-d',
            'status' => FiscalYearStatus::class,
        ];
    }

    protected static function newFactory(): FiscalYearFactory
    {
        return FiscalYearFactory::new();
    }

    public function periods(): HasMany
    {
        return $this->hasMany(ReportingPeriod::class)->orderBy('quarter');
    }
}

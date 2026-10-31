<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\User;
use Database\Factories\ServiceMonitoring\MonitoringExportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\ServiceMonitoring\Domain\Enums\ExportStatus;
use Modules\ServiceMonitoring\Domain\Enums\ReportRange;

/** One requested official-form export, built on the queue as the requester and stored on the private disk (the PublicFeedbackReportExport pattern). */
class MonitoringExport extends Model
{
    /** @use HasFactory<MonitoringExportFactory> */
    use HasFactory;

    use HasUuidPrimaryKey;

    protected $table = 'service_monitoring_exports';

    protected $fillable = [
        'requested_by',
        'scope_type',
        'scope_id',
        'fiscal_year_id',
        'quarter',
        'range',
        'format',
        'locale',
        'named',
        'status',
        'disk',
        'path',
        'filename',
        'error',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'quarter' => 'integer',
            'range' => ReportRange::class,
            'named' => 'boolean',
            'status' => ExportStatus::class,
            'completed_at' => 'datetime',
        ];
    }

    protected static function newFactory(): MonitoringExportFactory
    {
        return MonitoringExportFactory::new();
    }

    public const SCOPE_SUBMISSION = 'submission';

    public const SCOPE_SUB_CITY = 'sub_city';

    public const SCOPE_CITY = 'city';

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function isReady(): bool
    {
        return $this->status === ExportStatus::Ready && $this->path !== null;
    }
}

<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Database\Factories\ServiceMonitoring\VisitFindingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\ServiceMonitoring\Domain\Enums\FindingSeverity;

/** A finding of a monitoring visit, optionally against a form section. */
class VisitFinding extends Model
{
    /** @use HasFactory<VisitFindingFactory> */
    use HasFactory;

    use HasUuidPrimaryKey;

    protected $table = 'service_monitoring_findings';

    protected $fillable = [
        'visit_id',
        'section',
        'body',
        'severity',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'section' => 'integer',
            'severity' => FindingSeverity::class,
        ];
    }

    protected static function newFactory(): VisitFindingFactory
    {
        return VisitFindingFactory::new();
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(MonitoringVisit::class, 'visit_id');
    }

    public function actionItems(): HasMany
    {
        return $this->hasMany(ActionItem::class, 'finding_id');
    }
}

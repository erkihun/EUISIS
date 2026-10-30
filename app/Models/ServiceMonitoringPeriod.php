<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ServiceMonitoring\PeriodStatus;
use App\Enums\ServiceMonitoring\PeriodType;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A reporting window (fiscal year + 3/6/9/12 months). The year belongs here,
 * never in a form, so the same form serves every year.
 *
 * @property string $id
 * @property int $fiscal_year
 * @property PeriodType $period_type
 * @property PeriodStatus $status
 */
class ServiceMonitoringPeriod extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'fiscal_year',
        'period_type',
        'name_am',
        'name_en',
        'due_date',
        'status',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'fiscal_year' => 'integer',
            'period_type' => PeriodType::class,
            'status' => PeriodStatus::class,
            'due_date' => 'date',
        ];
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(ServiceMonitoringSubmission::class, 'period_id');
    }

    /** @param Builder<self> $query */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', PeriodStatus::Open->value);
    }

    public function isOpen(): bool
    {
        return $this->status === PeriodStatus::Open;
    }
}

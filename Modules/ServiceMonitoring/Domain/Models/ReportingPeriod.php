<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\ServiceMonitoring\ReportingPeriodFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\ServiceMonitoring\Domain\Enums\PeriodStatus;

/** One quarter of a fiscal year with its schedule (D5). The stored status is kept in step with the schedule; locked freezes the period. */
class ReportingPeriod extends Model
{
    /** @use HasFactory<ReportingPeriodFactory> */
    use HasFactory;

    use HasUuidPrimaryKey;

    protected $table = 'service_monitoring_periods';

    protected $fillable = [
        'fiscal_year_id',
        'quarter',
        'form_version',
        'starts_on',
        'ends_on',
        'opens_on',
        'submission_due_on',
        'subcity_review_due_on',
        'city_review_due_on',
        'status',
        'locked_at',
        'locked_by',
        'reopened_at',
        'reopened_by',
        'reopened_reason',
    ];

    protected function casts(): array
    {
        return [
            'quarter' => 'integer',
            'starts_on' => 'date:Y-m-d',
            'ends_on' => 'date:Y-m-d',
            'opens_on' => 'date:Y-m-d',
            'submission_due_on' => 'date:Y-m-d',
            'subcity_review_due_on' => 'date:Y-m-d',
            'city_review_due_on' => 'date:Y-m-d',
            'status' => PeriodStatus::class,
            'locked_at' => 'datetime',
            'reopened_at' => 'datetime',
        ];
    }

    protected static function newFactory(): ReportingPeriodFactory
    {
        return ReportingPeriodFactory::new();
    }

    /** Submission deadlines end at the end of the day in Addis Ababa (D6). */
    public const TIMEZONE = 'Africa/Addis_Ababa';

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(Submission::class, 'period_id');
    }

    public function lockedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by');
    }

    public function isLocked(): bool
    {
        return $this->status === PeriodStatus::Locked;
    }

    /** The status the schedule gives on $today (Addis date), unless the period is locked. */
    public function effectiveStatus(?CarbonImmutable $now = null): PeriodStatus
    {
        if ($this->isLocked()) {
            return PeriodStatus::Locked;
        }
        $today = ($now ?? CarbonImmutable::now())->setTimezone(self::TIMEZONE)->toDateString();

        return match (true) {
            $today < $this->opens_on->toDateString() => PeriodStatus::Scheduled,
            $today <= $this->submission_due_on->toDateString() => PeriodStatus::Open,
            default => PeriodStatus::Closed,
        };
    }

    /** The last moment a submission is accepted without an extension: 23:59:59 Addis time on the due date. */
    public function submissionDeadline(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->submission_due_on->toDateString().' 23:59:59', self::TIMEZONE);
    }

    public function label(?string $locale = null): string
    {
        return (string) __('service-monitoring.period_label', ['quarter' => $this->quarter, 'year' => $this->fiscalYear?->ec_year], $locale);
    }
}

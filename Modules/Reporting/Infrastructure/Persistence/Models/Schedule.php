<?php

declare(strict_types=1);

namespace Modules\Reporting\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** When a report type falls due, for whom, and how its deadlines are computed. */
final class Schedule extends ReportingModel
{
    protected $table = 'reporting_schedules';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'reviewer_user_ids' => 'array',
            'reminder_rules' => 'array',
            'is_active' => 'boolean',
            'starts_on' => 'date:Y-m-d',
            'ends_on' => 'date:Y-m-d',
            'fiscal_year_start_month' => 'integer',
            'opens_offset_days' => 'integer',
            'due_offset_days' => 'integer',
            'review_sla_working_days' => 'integer',
            'revision_days' => 'integer',
        ];
    }

    /** @return BelongsTo<covariant ReportingModel, $this> */
    public function reportType(): BelongsTo
    {
        return $this->belongsTo(ReportType::class, 'report_type_id');
    }

    /** @return HasMany<covariant ReportingModel, $this> */
    public function targets(): HasMany
    {
        return $this->hasMany(ScheduleTarget::class, 'schedule_id');
    }
}

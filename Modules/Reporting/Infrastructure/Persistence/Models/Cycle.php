<?php

declare(strict_types=1);

namespace Modules\Reporting\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One published reporting period of a report type, pinned to a template version and its review steps. */
final class Cycle extends ReportingModel
{
    protected $table = 'reporting_cycles';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'period_start' => 'date:Y-m-d',
            'period_end' => 'date:Y-m-d',
            'opens_at' => 'immutable_datetime',
            'due_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
            'reviewer_user_ids' => 'array',
            'reminder_rules' => 'array',
            'workflow' => 'array',
            'review_sla_working_days' => 'integer',
            'revision_days' => 'integer',
        ];
    }

    /** @return BelongsTo<covariant ReportingModel, $this> */
    public function reportType(): BelongsTo
    {
        return $this->belongsTo(ReportType::class, 'report_type_id');
    }

    /** @return BelongsTo<covariant ReportingModel, $this> */
    public function templateVersion(): BelongsTo
    {
        return $this->belongsTo(TemplateVersion::class, 'template_version_id');
    }

    /** @return HasMany<covariant ReportingModel, $this> */
    public function obligations(): HasMany
    {
        return $this->hasMany(Obligation::class, 'cycle_id');
    }
}

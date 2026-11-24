<?php

declare(strict_types=1);

namespace Modules\Reporting\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One version of a report type's template and review steps; immutable once published. */
final class TemplateVersion extends ReportingModel
{
    protected $table = 'reporting_template_versions';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'workflow' => 'array',
            'required_attachments' => 'array',
            'published_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    /** @return BelongsTo<covariant ReportingModel, $this> */
    public function reportType(): BelongsTo
    {
        return $this->belongsTo(ReportType::class, 'report_type_id');
    }

    /** @return HasMany<covariant ReportingModel, $this> */
    public function sections(): HasMany
    {
        return $this->hasMany(TemplateSection::class, 'template_version_id')->orderBy('position');
    }
}

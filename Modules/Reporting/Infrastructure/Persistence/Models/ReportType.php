<?php

declare(strict_types=1);

namespace Modules\Reporting\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/** A kind of report an institution may be asked for. */
final class ReportType extends ReportingModel
{
    protected $table = 'reporting_report_types';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'allow_nil_report' => 'boolean',
            'allow_self_approval' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<covariant ReportingModel, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(TemplateVersion::class, 'report_type_id');
    }
}

<?php

declare(strict_types=1);

namespace Modules\Reporting\Infrastructure\Persistence\Models;

/** A submission version used in a consolidation. */
final class ConsolidationSource extends ReportingModel
{
    protected $table = 'reporting_consolidation_sources';

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'version_no' => 'integer',
        ];
    }
}

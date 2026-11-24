<?php

declare(strict_types=1);

namespace Modules\Reporting\Infrastructure\Persistence\Models;

/** The working copy of one version of a report. Not a submission. */
final class Draft extends ReportingModel
{
    protected $table = 'reporting_drafts';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'content' => 'array',
            'metadata' => 'array',
            'is_nil' => 'boolean',
            'completeness_declared' => 'boolean',
            'version_no' => 'integer',
            'lock_version' => 'integer',
        ];
    }
}

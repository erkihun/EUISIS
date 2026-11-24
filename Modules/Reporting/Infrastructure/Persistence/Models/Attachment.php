<?php

declare(strict_types=1);

namespace Modules\Reporting\Infrastructure\Persistence\Models;

/** A file kept with one version of a report: the uploaded Word report, an annex or evidence. */
final class Attachment extends ReportingModel
{
    protected $table = 'reporting_attachments';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'version_no' => 'integer',
        ];
    }
}

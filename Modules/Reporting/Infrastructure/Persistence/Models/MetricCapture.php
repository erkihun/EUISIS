<?php

declare(strict_types=1);

namespace Modules\Reporting\Infrastructure\Persistence\Models;

/** An indicator value taken from another owner's public contract at a moment. */
final class MetricCapture extends ReportingModel
{
    protected $table = 'reporting_metric_captures';

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'scope' => 'array',
            'period_start' => 'date:Y-m-d',
            'period_end' => 'date:Y-m-d',
            'captured_at' => 'immutable_datetime',
            'is_frozen' => 'boolean',
        ];
    }
}

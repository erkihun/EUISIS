<?php

declare(strict_types=1);

namespace Modules\Reporting\Infrastructure\Persistence\Models;

/** An institution or person required to report under a schedule. */
final class ScheduleTarget extends ReportingModel
{
    protected $table = 'reporting_schedule_targets';
}

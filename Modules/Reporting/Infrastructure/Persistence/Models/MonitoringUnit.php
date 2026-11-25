<?php

declare(strict_types=1);

namespace Modules\Reporting\Infrastructure\Persistence\Models;

/** The work unit an institution has designated to follow up reports. One per institution. */
final class MonitoringUnit extends ReportingModel
{
    protected $table = 'reporting_monitoring_units';
}

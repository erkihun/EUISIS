<?php

declare(strict_types=1);

namespace Modules\Grievance\Contracts\Data;

enum CommitteeType: string
{
    case Grievance = 'grievance';
    case Disciplinary = 'disciplinary';
    case Tribunal = 'tribunal';
    // EPMS panels reuse the committee model (docs/epms-workflow.md).
    case PerformanceAppeal = 'performance_appeal';
    case PerformanceCalibration = 'performance_calibration';
}

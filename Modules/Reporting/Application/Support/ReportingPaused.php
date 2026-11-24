<?php

declare(strict_types=1);

namespace Modules\Reporting\Application\Support;

use RuntimeException;

/** The maintenance switch is on: writes and scheduled work are refused, data stays readable. */
final class ReportingPaused extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('reporting_paused');
    }
}

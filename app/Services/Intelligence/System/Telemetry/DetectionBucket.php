<?php

declare(strict_types=1);

namespace App\Services\Intelligence\System\Telemetry;

/** The one bucket size telemetry is stored in: five minutes. */
final class DetectionBucket
{
    public const MINUTES = 5;

    public const SECONDS = 300;
}

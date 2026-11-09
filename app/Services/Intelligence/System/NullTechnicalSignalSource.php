<?php

declare(strict_types=1);

namespace App\Services\Intelligence\System;

use App\Services\Intelligence\Contracts\TechnicalSignalSource;
use Carbon\CarbonImmutable;

/** The answer when no telemetry is collected: unknown, so no correlation is ever asserted. */
final class NullTechnicalSignalSource implements TechnicalSignalSource
{
    public function failuresFor(string $routeGroup, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return ['known' => false, 'requests' => 0, 'failures' => 0, 'failure_rate_percent' => null, 'incidents' => 0];
    }
}

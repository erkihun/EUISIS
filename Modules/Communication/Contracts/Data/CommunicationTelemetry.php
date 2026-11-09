<?php

declare(strict_types=1);

namespace Modules\Communication\Contracts\Data;

/** The cache keys Communication counts its operational metrics under, for system telemetry. */
final class CommunicationTelemetry
{
    public const BROADCAST_FAILURES_KEY = 'communication:metrics:broadcast_failures';
}

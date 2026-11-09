<?php

declare(strict_types=1);

namespace Modules\Transport\Contracts\Data;

/**
 * The values a trip's status takes, published for reports that count trips
 * by status. The lifecycle rules themselves stay inside the module;
 * ModuleBoundariesTest keeps this list equal to the module's own enum.
 */
final class TripStatus
{
    public const PLANNED = 'planned';

    public const ASSIGNED = 'assigned';

    public const BOARDING = 'boarding';

    public const IN_PROGRESS = 'in_progress';

    public const COMPLETED = 'completed';

    public const CANCELLED = 'cancelled';

    public const FAILED = 'failed';

    public const ALL = [self::PLANNED, self::ASSIGNED, self::BOARDING, self::IN_PROGRESS, self::COMPLETED, self::CANCELLED, self::FAILED];
}

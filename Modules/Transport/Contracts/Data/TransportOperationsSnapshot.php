<?php

declare(strict_types=1);

namespace Modules\Transport\Contracts\Data;

final readonly class TransportOperationsSnapshot
{
    public function __construct(
        public int $activeDevices,
        /** Active devices never seen, or silent past the configured inactivity timeout. */
        public int $silentDevices,
        public int $offlineBoardingsPending,
        /** Age of the oldest offline boarding still waiting to sync; 0 when none. */
        public int $oldestOfflineBoardingMinutes,
        /** Trips still boarding or in progress after their trip date. */
        public int $staleOpenTrips,
        public int $boardingsLast15Minutes,
        public int $refusalsLast15Minutes,
    ) {}
}

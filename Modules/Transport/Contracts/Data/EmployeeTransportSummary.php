<?php

declare(strict_types=1);

namespace Modules\Transport\Contracts\Data;

/**
 * What EmployeeTransportActivity returns. Plain values only; names are given
 * in English and Amharic, dates as Y-m-d and times as H:i.
 *
 * @phpstan-type Pass array{id: string, status: string, route: ?string, route_am: ?string, route_code: ?string, origin: ?string, origin_am: ?string, destination: ?string, destination_am: ?string, provider: ?string, provider_am: ?string, boarding_stop: ?string, boarding_stop_am: ?string, evening_stop: ?string, evening_stop_am: ?string, valid_from: ?string, valid_until: ?string}
 * @phpstan-type Trip array{id: string, trip_number: ?string, direction: ?string, departure_time: ?string, status: ?string, boarded_count: ?int, capacity: ?int}
 * @phpstan-type Boarding array{date: ?string, time: ?string, route: ?string, route_am: ?string, provider: ?string, provider_am: ?string, direction: ?string, stop: ?string, stop_am: ?string, status: string, rejection_reason: ?string}
 */
final readonly class EmployeeTransportSummary
{
    /**
     * @param  list<Pass>  $passes  up to five, active first, latest validity first
     * @param  list<Trip>  $todayTrips  today's trips on the current pass's route
     * @param  list<Boarding>  $recentBoardings  the last fifteen scans
     */
    public function __construct(
        public array $passes,
        public array $todayTrips,
        public int $ridesThisMonth,
        public int $morningRidesThisMonth,
        public int $eveningRidesThisMonth,
        public array $recentBoardings,
    ) {}

    /** The shape My Portal has always received for its transport card. */
    public function toArray(): array
    {
        return [
            'type' => 'transport',
            'passes' => $this->passes,
            'today_trips' => $this->todayTrips,
            'rides_this_month' => $this->ridesThisMonth,
            'morning_rides_this_month' => $this->morningRidesThisMonth,
            'evening_rides_this_month' => $this->eveningRidesThisMonth,
            'transactions' => $this->recentBoardings,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Enums\Transport;

/**
 * Deterministic operational anomalies. An exception records what happened;
 * it never accuses anyone, and it never deletes the boarding evidence.
 */
enum TransportExceptionType: string
{
    case Overcapacity = 'overcapacity';
    case DuplicateBoardingAttempt = 'duplicate_boarding_attempt';
    case SimultaneousBoarding = 'simultaneous_boarding';
    case InactiveCardUse = 'inactive_card_use';
    case WrongRoute = 'wrong_route';
    case UnregisteredDevice = 'unregistered_device';
    case DeviceTripMismatch = 'device_trip_mismatch';
    case TripOutsideSchedule = 'trip_outside_schedule';
    case ManualBoarding = 'manual_boarding';
    case ExcessiveManualExceptions = 'excessive_manual_exceptions';
    case VehicleSubstituted = 'vehicle_substituted';
    case RouteDeviation = 'route_deviation';
    case TripCancelled = 'trip_cancelled';
    case TripFailed = 'trip_failed';
    case OfflineReplay = 'offline_replay';
    case BoardingOnUncompletedTrip = 'boarding_on_uncompleted_trip';
    case EligibilityWithdrawn = 'eligibility_withdrawn';
    case UnknownCard = 'unknown_card';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}

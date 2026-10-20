<?php

declare(strict_types=1);

namespace App\Enums\Transport;

enum TransportDisputeType: string
{
    case MissingTransaction = 'missing_transaction';
    case TripCancellation = 'trip_cancellation';
    case DeviceOutage = 'device_outage';
    case VehicleSubstitution = 'vehicle_substitution';
    case Other = 'other';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}

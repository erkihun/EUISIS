<?php

declare(strict_types=1);

namespace Modules\Transport\Application\Services;

use Illuminate\Support\Carbon;
use Modules\Transport\Domain\Enums\TransportExceptionStatus;
use Modules\Transport\Domain\Enums\TransportExceptionType;
use Modules\Transport\Domain\Models\TransportException;

/**
 * Writes operational exceptions. An exception is a fact about the service
 * (a bus over capacity, a second scan, a replaced vehicle); it accuses no
 * one and leaves the boarding evidence untouched.
 */
final class TransportExceptionRecorder
{
    /**
     * @param  array<string, mixed>  $detail
     * @param  array{trip_id?: string|null, transaction_id?: string|null, employee_id?: string|null, vehicle_id?: string|null, device_id?: string|null}  $links
     */
    public function record(
        string $providerId,
        TransportExceptionType $type,
        array $detail = [],
        array $links = [],
        string $severity = 'warning',
        ?Carbon $occurredAt = null,
    ): TransportException {
        return TransportException::query()->create([
            'provider_id' => $providerId,
            'transport_trip_id' => $links['trip_id'] ?? null,
            'transport_transaction_id' => $links['transaction_id'] ?? null,
            'employee_id' => $links['employee_id'] ?? null,
            'transport_vehicle_id' => $links['vehicle_id'] ?? null,
            'transport_device_id' => $links['device_id'] ?? null,
            'type' => $type,
            'severity' => $severity,
            'status' => TransportExceptionStatus::Open,
            'detail' => $detail,
            'occurred_at' => $occurredAt ?? now(),
        ]);
    }
}

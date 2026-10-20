<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransportExceptionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'provider_id' => $this->provider_id,
            'transport_trip_id' => $this->transport_trip_id,
            'transport_transaction_id' => $this->transport_transaction_id,
            'employee_id' => $this->employee_id,
            'type' => $this->type?->value,
            'severity' => $this->severity,
            'status' => $this->status?->value,
            'detail' => $this->detail,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'review_notes' => $this->review_notes,
            'provider' => $this->whenLoaded('provider', fn () => $this->provider ? ['id' => $this->provider->id, 'provider_code' => $this->provider->provider_code, 'name_en' => $this->provider->name_en, 'name_am' => $this->provider->name_am] : null),
            'trip' => $this->whenLoaded('trip', fn () => $this->trip ? ['id' => $this->trip->id, 'trip_number' => $this->trip->trip_number, 'trip_date' => $this->trip->trip_date?->toDateString()] : null),
            'employee' => $this->whenLoaded('employee', fn () => $this->employee ? ['id' => $this->employee->id, 'employee_number' => $this->employee->employee_number, 'full_name' => $this->employee->full_name] : null),
            'vehicle' => $this->whenLoaded('vehicle', fn () => $this->vehicle ? ['id' => $this->vehicle->id, 'plate_number' => $this->vehicle->plate_number] : null),
            'device' => $this->whenLoaded('device', fn () => $this->device ? ['id' => $this->device->id, 'device_code' => $this->device->device_code] : null),
            'reviewed_by' => $this->whenLoaded('reviewedBy', fn () => $this->reviewedBy?->name),
        ];
    }
}

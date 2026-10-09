<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransportTransactionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'provider_id' => $this->provider_id,
            'employee_id' => $this->employee_id,
            'transport_pass_id' => $this->transport_pass_id,
            'transport_route_id' => $this->transport_route_id,
            'transport_trip_id' => $this->transport_trip_id,
            'scanned_at' => $this->scanned_at?->toIso8601String(),
            'transaction_date' => $this->transaction_date?->toDateString(),
            'status' => $this->status,
            'result_code' => $this->result_code,
            'rejection_reason' => $this->rejection_reason,
            'employee' => $this->whenLoaded('employee', fn () => [
                'id' => $this->employee?->id,
                'employee_number' => $this->employee?->employee_number,
                'full_name' => $this->employee?->full_name,
                'organization' => $this->employee?->currentAssignment?->organization?->name_en,
            ]),
            'route' => $this->whenLoaded('route', fn () => $this->route ? [
                'id' => $this->route->id,
                'name_en' => $this->route->name_en,
                'route_code' => $this->route->route_code,
            ] : null),
            'trip' => $this->whenLoaded('trip', fn () => $this->trip ? [
                'id' => $this->trip->id,
                'trip_number' => $this->trip->trip_number,
                'status' => $this->trip->status,
            ] : null),
        ];
    }
}

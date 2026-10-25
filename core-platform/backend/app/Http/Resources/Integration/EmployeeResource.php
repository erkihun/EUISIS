<?php

declare(strict_types=1);

namespace App\Http\Resources\Integration;

use App\Models\ApiClient;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An employee for integrations: identity, number, status and current
 * placement (employees:basic-read); split names and gender only with
 * employees:read. Personal identifiers, contacts, photo and documents never.
 */
class EmployeeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $client = $request->attributes->get('api_client');
        $assignment = $this->currentAssignment;

        return [
            'id' => $this->id,
            'employee_number' => $this->employee_number,
            'full_name' => $this->full_name,
            'name_en' => $this->name_en,
            'status' => $this->status,
            'current_assignment' => $assignment === null ? null : [
                'id' => $assignment->id,
                'organization_id' => $assignment->organization_id,
                'organization_unit_id' => $assignment->organization_unit_id,
                'position_id' => $assignment->position_id,
                'status' => $assignment->assignment_status,
                'effective_from' => $assignment->effective_from?->toDateString(),
            ],
            $this->mergeWhen($client instanceof ApiClient && $client->hasScope('employees:read'), fn (): array => [
                'first_name' => $this->first_name,
                'middle_name' => $this->middle_name,
                'last_name' => $this->last_name,
                'gender' => $this->gender,
            ]),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

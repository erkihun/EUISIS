<?php

declare(strict_types=1);

namespace App\Http\Resources\Integration;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** A placement of an employee in a position of an organization, current or historical. */
class AssignmentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'organization_id' => $this->organization_id,
            'organization_unit_id' => $this->organization_unit_id,
            'position_id' => $this->position_id,
            'status' => $this->assignment_status,
            'is_current' => (bool) $this->is_current,
            'effective_from' => $this->effective_from?->toDateString(),
            'effective_to' => $this->effective_to?->toDateString(),
            'reason' => $this->reason,
        ];
    }
}

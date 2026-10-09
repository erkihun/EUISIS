<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeCafeteriaExclusionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'employee_id'       => $this->employee_id,
            'employee_name'     => $this->employee?->full_name,
            'employee_number'   => $this->employee?->employee_number,
            'exclusion_type'    => $this->exclusion_type->value,
            'exclusion_type_label' => $this->exclusion_type->label(),
            'starts_on'         => $this->starts_on->toDateString(),
            'ends_on'           => $this->ends_on?->toDateString(),
            'return_to_work_on' => $this->return_to_work_on?->toDateString(),
            'is_open_ended'     => $this->is_open_ended,
            'reason_en'         => $this->reason_en,
            'reason_am'         => $this->reason_am,
            'status'            => $this->status->value,
            'status_label'      => $this->status->label(),
            'ended_at'          => $this->ended_at?->toDateTimeString(),
            'created_at'        => $this->created_at?->toDateTimeString(),
            'updated_at'        => $this->updated_at?->toDateTimeString(),
            'deleted_at'        => $this->deleted_at?->toDateTimeString(),
            'can' => [
                'update'  => $request->user()?->can('update', $this->resource) ?? false,
                'end'     => $request->user()?->can('end', $this->resource) ?? false,
                'archive' => $request->user()?->can('archive', $this->resource) ?? false,
                'restore' => $request->user()?->can('restore', $this->resource) ?? false,
            ],
        ];
    }
}

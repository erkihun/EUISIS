<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CafeteriaSpecialDayResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                   => $this->id,
            'special_date'         => $this->special_date->toDateString(),
            'name_en'              => $this->name_en,
            'name_am'              => $this->name_am,
            'day_type'             => $this->day_type->value,
            'day_type_label'       => $this->day_type->label(),
            'is_open'              => $this->is_open,
            'is_subsidy_day'       => $this->is_subsidy_day,
            'cafeteria_provider_id' => $this->cafeteria_provider_id,
            'provider_name'        => $this->provider?->name_en,
            'organization_id'      => $this->organization_id,
            'organization_name'    => $this->organization?->name,
            'open_time'            => $this->open_time,
            'close_time'           => $this->close_time,
            'reason_en'            => $this->reason_en,
            'reason_am'            => $this->reason_am,
            'is_active'            => $this->is_active,
            'created_at'           => $this->created_at?->toDateTimeString(),
            'updated_at'           => $this->updated_at?->toDateTimeString(),
            'deleted_at'           => $this->deleted_at?->toDateTimeString(),
            'can' => [
                'update'  => $request->user()?->can('update', $this->resource) ?? false,
                'archive' => $request->user()?->can('archive', $this->resource) ?? false,
                'restore' => $request->user()?->can('restore', $this->resource) ?? false,
            ],
        ];
    }
}

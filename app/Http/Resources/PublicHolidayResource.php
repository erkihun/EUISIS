<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PublicHolidayResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id'              => $this->id,
            'name_en'         => $this->name_en,
            'name_am'         => $this->name_am,
            'holiday_date'    => $this->holiday_date?->toDateString(),
            'is_recurring'    => $this->is_recurring,
            'recurrence_type' => $this->recurrence_type,
            'country_code'    => $this->country_code,
            'region'          => $this->region,
            'is_active'       => $this->is_active,
            'description'     => $this->description,
            'created_at'      => $this->created_at?->toISOString(),
            'deleted_at'      => $this->deleted_at?->toISOString(),
            'can'             => [
                'update'  => $user?->can('update', $this->resource) ?? false,
                'archive' => $user?->can('archive', $this->resource) ?? false,
            ],
        ];
    }
}

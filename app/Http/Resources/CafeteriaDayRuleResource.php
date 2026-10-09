<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CafeteriaDayRuleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'day_of_week'    => $this->day_of_week,
            'day_name'       => $this->day_name,
            'is_open'        => $this->is_open,
            'is_subsidy_day' => $this->is_subsidy_day,
            'open_time'      => $this->open_time,
            'close_time'     => $this->close_time,
            'notes'          => $this->notes,
            'effective_from' => $this->effective_from?->toDateString(),
            'effective_to'   => $this->effective_to?->toDateString(),
            'is_active'      => $this->is_active,
            'updated_at'     => $this->updated_at?->toDateTimeString(),
            'can' => [
                'update' => $request->user()?->can('update', $this->resource) ?? false,
            ],
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Resources\ProviderPortal;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CafeteriaFoodOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'cafeteria_provider_id' => $this->cafeteria_provider_id,
            'cafeteria_menu_id' => $this->cafeteria_menu_id,
            'employee_id' => $this->employee_id,
            'order_date' => $this->order_date?->toDateString(),
            'ordered_at' => $this->ordered_at?->toISOString(),
            'served_at' => $this->served_at?->toISOString(),
            'status' => $this->status,
            'quantity' => (int) $this->quantity,
            'total_amount' => (float) $this->total_amount,
            'subsidy_amount_applied' => (float) $this->subsidy_amount_applied,
            'employee_payable_amount' => (float) $this->employee_payable_amount,
            'notes' => $this->notes,
            'employee' => $this->whenLoaded('employee', fn () => [
                'id' => $this->employee?->id,
                'employee_number' => $this->employee?->employee_number,
                'name' => $this->employee?->full_name,
            ]),
            'menu' => $this->whenLoaded('menu', fn () => [
                'id' => $this->menu?->id,
                'title_en' => $this->menu?->title_en,
                'title_am' => $this->menu?->title_am,
            ]),
        ];
    }
}

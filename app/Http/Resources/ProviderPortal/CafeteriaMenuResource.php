<?php

declare(strict_types=1);

namespace App\Http\Resources\ProviderPortal;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CafeteriaMenuResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'cafeteria_provider_id' => $this->cafeteria_provider_id,
            'menu_date' => $this->menu_date?->toDateString(),
            'title_en' => $this->title_en,
            'title_am' => $this->title_am,
            'description_en' => $this->description_en,
            'description_am' => $this->description_am,
            'meal_type' => $this->meal_type,
            'price' => (float) $this->price,
            'subsidy_eligible' => (bool) $this->subsidy_eligible,
            'max_orders' => $this->max_orders,
            'order_cutoff_at' => $this->order_cutoff_at?->toISOString(),
            'status' => $this->status,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item): array => [
                'id' => $item->id,
                'name_en' => $item->name_en,
                'name_am' => $item->name_am,
                'description_en' => $item->description_en,
                'description_am' => $item->description_am,
                'item_type' => $item->item_type,
                'is_available' => (bool) $item->is_available,
                'sort_order' => (int) $item->sort_order,
            ])->values()->all()),
            'orders_count' => $this->whenCounted('orders'),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}

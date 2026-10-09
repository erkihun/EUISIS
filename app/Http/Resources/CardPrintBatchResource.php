<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CardPrintBatchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'batch_number' => $this->batch_number,
            'status' => $this->status,
            'total_cards' => $this->total_cards,
            'printed_count' => $this->printed_count,
            'spoiled_count' => $this->spoiled_count,
            'printer_notes' => $this->printer_notes,
            'printed_at' => $this->printed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'created_by' => $this->whenLoaded('creator', fn () => [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
            ]),
            'printed_by' => $this->whenLoaded('printer', fn () => $this->printer ? [
                'id' => $this->printer->id,
                'name' => $this->printer->name,
            ] : null),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'status' => $item->status,
                'spoiled' => $item->spoiled,
                'reprint_reason' => $item->reprint_reason,
                'card' => $item->card ? [
                    'id' => $item->card->id,
                    'card_number' => $item->card->card_number,
                    'status' => $item->card->status?->value,
                    'employee' => $item->card->employee ? [
                        'employee_number' => $item->card->employee->employee_number,
                        'full_name' => $item->card->employee->full_name,
                    ] : null,
                ] : null,
            ])),
            'can' => $user ? [
                'view' => $user->can('view', $this->resource),
                'markPrinted' => $user->can('markPrinted', $this->resource),
            ] : [],
        ];
    }
}

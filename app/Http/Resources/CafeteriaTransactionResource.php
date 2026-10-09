<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CafeteriaTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $employee = $this->relationLoaded('employee') ? $this->employee : null;
        $assignment = $employee?->currentAssignment;

        return [
            'id' => $this->id,
            'transaction_number' => $this->transaction_number,
            'employee_id' => $this->employee_id,
            'id_card_id' => $this->id_card_id,
            'cafeteria_provider_id' => $this->cafeteria_provider_id,
            'transaction_date' => $this->transaction_date?->toDateString(),
            'transaction_time' => $this->transaction_time,
            'scanned_at' => $this->scanned_at?->toISOString(),
            'meal_amount' => (float) $this->meal_amount,
            'subsidy_amount_applied' => (float) $this->subsidy_amount_applied,
            'employee_payable_amount' => (float) $this->employee_payable_amount,
            'deduction_amount' => (float) $this->deduction_amount,
            'transaction_type' => $this->transaction_type,
            'status' => $this->status?->value,
            'status_color' => $this->status?->color(),
            'scan_sequence_for_day' => $this->scan_sequence_for_day,
            'is_extra_scan' => $this->is_extra_scan,
            'is_holiday' => $this->is_holiday,
            'is_working_day' => $this->is_working_day,
            'notes' => $this->notes,
            'usage_mode' => $this->usage_mode?->value,
            'consumed_days_count' => (int) $this->consumed_days_count,
            'consumed_dates' => $this->whenLoaded(
                'consumedDays',
                fn () => $this->consumedDays
                    ->whereNull('reversed_at')
                    ->pluck('consumed_date')
                    ->map(fn ($date) => $date?->toDateString())
                    ->values()
                    ->all(),
                [],
            ),
            'employee' => $employee ? [
                'id' => $employee->id,
                'employee_number' => $employee->employee_number,
                'name_en' => $employee->full_name,
                'name_am' => $employee->full_name,
                'display_name' => $employee->full_name,
                'photo_url' => $employee->photo_url,
                'organization_name' => $assignment?->organization?->name_en,
                'organization_unit_name' => $assignment?->organizationUnit?->name_en,
                'position_title' => $assignment?->position?->title_en,
            ] : null,
            'provider' => $this->whenLoaded('provider', fn () => [
                'id' => $this->provider?->id,
                'name_en' => $this->provider?->name_en,
                'name_am' => $this->provider?->name_am,
                'code' => $this->provider?->code,
            ]),
            'created_at' => $this->created_at?->toISOString(),
            'can' => [
                'reverse' => $user?->can('reverse', $this->resource) ?? false,
            ],
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CafeteriaSubsidyRuleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id'               => $this->id,
            'code'             => $this->code,
            'name_en'          => $this->name_en,
            'name_am'          => $this->name_am,
            'subsidy_amount'   => (float) $this->subsidy_amount,
            'currency'         => $this->currency,
            'effective_from'   => $this->effective_from?->toDateString(),
            'effective_to'     => $this->effective_to?->toDateString(),
            'applies_to'       => $this->applies_to,
            'organization_id'  => $this->organization_id,
            'employee_type'    => $this->employee_type,
            'is_active'        => $this->is_active,
            'exclude_weekends' => $this->exclude_weekends,
            'notes'            => $this->notes,
            'created_at'       => $this->created_at?->toISOString(),
            'deleted_at'       => $this->deleted_at?->toISOString(),
            'can'              => [
                'update'  => $user?->can('update', $this->resource) ?? false,
                'archive' => $user?->can('archive', $this->resource) ?? false,
            ],
        ];
    }
}

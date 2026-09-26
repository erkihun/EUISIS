<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\CafeteriaReportRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CafeteriaReportRunResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'report_number' => $this->report_number,
            'report_type' => $this->report_type?->value,
            'period_start' => $this->period_start?->toDateString(),
            'period_end' => $this->period_end?->toDateString(),
            'status' => $this->status,
            'generated_at' => $this->generated_at?->toISOString(),
            'organization_id' => $this->organization_id,
            'organization' => $this->whenLoaded('organization', fn () => $this->organization?->only(['name_en', 'name_am'])),
            'totals' => $this->totals,
            'filters' => $this->filters,
            'can' => [
                'export' => $user?->can('export', CafeteriaReportRun::class) ?? false,
            ],
        ];
    }
}

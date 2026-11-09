<?php

namespace Modules\Court\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class CourtCaseResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'case_number' => $this->case_number,
            'title' => $this->title,
            'status' => $this->status,
            'filing_date' => optional($this->filing_date)->toDateString(),
            'first_hearing_date' => optional($this->first_hearing_date)->toDateString(),
            'case_type' => $this->whenLoaded('caseType', function () {
                return [
                    'id' => $this->caseType?->id,
                    'name' => $this->caseType?->name,
                    'prefix' => $this->caseType?->prefix,
                ];
            }),
            'applicant' => $this->whenLoaded('applicant', function () {
                return [
                    'id' => $this->applicant?->id,
                    'name' => $this->applicant?->full_name,
                    'email' => $this->applicant?->email,
                ];
            }),
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}

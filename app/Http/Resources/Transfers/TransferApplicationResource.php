<?php

declare(strict_types=1);

namespace App\Http\Resources\Transfers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransferApplicationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'announcement' => $this->whenLoaded('announcement', fn () => [
                'id' => $this->announcement->id,
                'position' => $this->announcement->position ? [
                    'id' => $this->announcement->position->id,
                    'title_en' => $this->announcement->position->title_en,
                    'title_am' => $this->announcement->position->title_am,
                ] : null,
            ]),
            'employee' => $this->whenLoaded('employee', fn () => [
                'id' => $this->employee->id,
                'employee_number' => $this->employee->employee_number,
                'full_name' => $this->employee->full_name,
            ]),
            'releasing_organization' => $this->whenLoaded('releasingOrganization', fn () => [
                'id' => $this->releasingOrganization->id,
                'name_en' => $this->releasingOrganization->name_en,
                'name_am' => $this->releasingOrganization->name_am,
            ]),
            'receiving_organization' => $this->whenLoaded('receivingOrganization', fn () => [
                'id' => $this->receivingOrganization->id,
                'name_en' => $this->receivingOrganization->name_en,
                'name_am' => $this->receivingOrganization->name_am,
            ]),
            'status' => $this->status?->value,
            'applicant_notes' => $this->applicant_notes,
            'rejected_reason' => $this->rejected_reason,
            'submitted_at' => $this->submitted_at?->toISOString(),
            'selected_at' => $this->selected_at?->toISOString(),
            'documents' => TransferApplicationDocumentResource::collection($this->whenLoaded('documents')),
            'approvals' => TransferApprovalResource::collection($this->whenLoaded('approvals')),
            'screening_reviews' => TransferScreeningReviewResource::collection($this->whenLoaded('screeningReviews')),
            'rule_overrides' => TransferRuleOverrideResource::collection($this->whenLoaded('ruleOverrides')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}

<?php

declare(strict_types=1);

namespace Modules\Transfer\Http\Resources;

use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransferApplicationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // The advertised line the applicant chose; older single-position
        // announcements carry the position on the announcement itself.
        $position = $this->relationLoaded('announcementPosition') ? $this->announcementPosition?->position : null;
        $position ??= $this->relationLoaded('announcement') ? $this->announcement?->position : null;

        return [
            'id' => $this->id,
            'application_number' => $this->application_number,
            'announcement' => $this->whenLoaded('announcement', fn () => [
                'id' => $this->announcement->id,
                'closing_date' => $this->announcement->closing_date?->toDateString(),
            ]),
            'position' => $position === null ? null : [
                'id' => $position->id,
                'code' => $position->job_position_code,
                'title_en' => $position->title_en,
                'title_am' => $position->title_am,
            ],
            // Display fields only: never the applicant's contact or birth data (SEC-9).
            'employee' => $this->whenLoaded('employee', fn () => [
                'id' => $this->employee->id,
                'employee_number' => $this->employee->employee_number,
                'full_name' => $this->employee->full_name,
            ]),
            'current_position' => $this->whenLoaded('currentAssignment', fn () => $this->currentAssignment?->position ? [
                'title_en' => $this->currentAssignment->position->title_en,
                'title_am' => $this->currentAssignment->position->title_am,
            ] : null),
            'releasing_organization' => $this->whenLoaded('releasingOrganization', fn () => $this->organization($this->releasingOrganization)),
            'receiving_organization' => $this->whenLoaded('receivingOrganization', fn () => $this->organization($this->receivingOrganization)),
            'status' => $this->status?->value,
            'applicant_notes' => $this->applicant_notes,
            'rejected_reason' => $this->rejected_reason,
            'withdrawal_reason' => $this->withdrawal_reason,
            'implementation_failure' => $this->implementation_failure,
            'submitted_at' => $this->submitted_at?->toISOString(),
            'selected_at' => $this->selected_at?->toISOString(),
            'effective_date' => $this->effective_date?->toDateString(),
            'approved_at' => $this->approved_at?->toISOString(),
            'implemented_at' => $this->implemented_at?->toISOString(),
            // Resolved to plain lists: a nested resource collection reaches an
            // Inertia page wrapped as {data: [...]}, not as the array it reads.
            'documents' => $this->whenLoaded('documents', fn () => TransferApplicationDocumentResource::collection($this->documents)->resolve()),
            'approvals' => $this->whenLoaded('approvals', fn () => TransferApprovalResource::collection($this->approvals)->resolve()),
            'screening_reviews' => $this->whenLoaded('screeningReviews', fn () => TransferScreeningReviewResource::collection($this->screeningReviews)->resolve()),
            'rule_overrides' => $this->whenLoaded('ruleOverrides', fn () => TransferRuleOverrideResource::collection($this->ruleOverrides)->resolve()),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

    /** @return array{id: string, name_en: string, name_am: string|null}|null */
    private function organization(?Organization $organization): ?array
    {
        return $organization === null ? null : [
            'id' => $organization->id,
            'name_en' => $organization->name_en,
            'name_am' => $organization->name_am,
        ];
    }
}

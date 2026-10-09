<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CardRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'request_type' => $this->request_type?->value,
            'status' => $this->status?->value,
            'request_reason' => $this->request_reason,
            'verification_notes' => $this->verification_notes,
            'rejection_reason' => $this->rejection_reason,
            'cancellation_reason' => $this->cancellation_reason,
            'notes' => $this->notes,
            'previous_card_id' => $this->previous_card_id,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'verified_at' => $this->verified_at?->toIso8601String(),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'rejected_at' => $this->rejected_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'requested_by' => $this->whenLoaded('requester', fn () => [
                'id' => $this->requester->id,
                'name' => $this->requester->name,
            ]),
            'reviewed_by' => $this->whenLoaded('reviewer', fn () => $this->reviewer ? [
                'id' => $this->reviewer->id,
                'name' => $this->reviewer->name,
            ] : null),
            'approved_by' => $this->whenLoaded('approver', fn () => $this->approver ? [
                'id' => $this->approver->id,
                'name' => $this->approver->name,
            ] : null),
            'rejected_by' => $this->whenLoaded('rejecter', fn () => $this->rejecter ? [
                'id' => $this->rejecter->id,
                'name' => $this->rejecter->name,
            ] : null),
            'employee' => $this->whenLoaded('employee', fn () => [
                'id' => $this->employee->id,
                'employee_number' => $this->employee->employee_number,
                'full_name' => $this->employee->full_name,
                'status' => $this->employee->status?->value,
                'photo_path' => $this->employee->photo_path,
                'photo_url' => $this->employee->photo_url,
                'current_assignment' => $this->employee->currentAssignment ? [
                    'organization' => $this->employee->currentAssignment->organization ? [
                        'id' => $this->employee->currentAssignment->organization->id,
                        'name_en' => $this->employee->currentAssignment->organization->name_en,
                        'code' => $this->employee->currentAssignment->organization->code,
                    ] : null,
                    'position' => $this->employee->currentAssignment->position ? [
                        'title_en' => $this->employee->currentAssignment->position->title_en,
                    ] : null,
                ] : null,
            ]),
            'cards' => $this->whenLoaded('cards', fn () => $this->cards->map(fn ($card) => [
                'id' => $card->id,
                'card_number' => $card->card_number,
                'status' => $card->status?->value,
            ])),
            'can' => $user ? [
                'view' => $user->can('view', $this->resource),
                'verify' => $user->can('verify', $this->resource),
                'approve' => $user->can('approve', $this->resource),
                'reject' => $user->can('reject', $this->resource),
                'cancel' => $user->can('cancel', $this->resource),
            ] : [],
        ];
    }
}

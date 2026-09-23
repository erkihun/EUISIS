<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\OrganizationalChangeRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin OrganizationalChangeRequest
 */
class OrganizationalChangeRequestResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'request_no' => $this->request_no,
            'status' => $this->status->value,
            'request_type' => $this->request_type->value,
            'category' => $this->category->value,
            'priority' => $this->priority,
            'reason' => $this->reason,
            'requested_effective_date' => $this->requested_effective_date?->toDateString(),
            'revision' => $this->revision,

            'organization' => $this->whenLoaded('organization', fn (): array => [
                'id' => (string) $this->organization->id,
                'name_en' => $this->organization->name_en,
                'name_am' => $this->organization->name_am,
                'code' => $this->organization->code,
            ]),
            'requester' => $this->whenLoaded('requester', fn (): ?array => $this->requester ? [
                'id' => $this->requester->id,
                'name' => $this->requester->name,
            ] : null),
            'reviewer' => $this->whenLoaded('reviewer', fn (): ?array => $this->reviewer ? [
                'id' => $this->reviewer->id,
                'name' => $this->reviewer->name,
            ] : null),
            'approver' => $this->whenLoaded('approver', fn (): ?array => $this->approver ? [
                'id' => $this->approver->id,
                'name' => $this->approver->name,
            ] : null),
            'implementer' => $this->whenLoaded('implementer', fn (): ?array => $this->implementer ? [
                'id' => $this->implementer->id,
                'name' => $this->implementer->name,
            ] : null),
            'implementation_assignee' => $this->whenLoaded('implementationAssignee', fn (): ?array => $this->implementationAssignee ? [
                'id' => $this->implementationAssignee->id,
                'name' => $this->implementationAssignee->name,
            ] : null),
            'implementing_unit' => $this->whenLoaded('implementingUnit', fn (): ?array => $this->implementingUnit ? [
                'id' => (string) $this->implementingUnit->id,
                'name_en' => $this->implementingUnit->name_en,
                'name_am' => $this->implementingUnit->name_am,
            ] : null),
            'implementing_unit_key' => $this->implementing_unit_key,

            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'review_started_at' => $this->review_started_at?->toIso8601String(),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'rejected_at' => $this->rejected_at?->toIso8601String(),
            'implementation_assigned_at' => $this->implementation_assigned_at?->toIso8601String(),
            'implementation_started_at' => $this->implementation_started_at?->toIso8601String(),
            'implemented_at' => $this->implemented_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'blocked_at' => $this->blocked_at?->toIso8601String(),

            'decision_comment' => $this->decision_comment,
            'implementation_note' => $this->implementation_note,
            'blocked_reasons' => $this->blocked_reasons,
            'implementation_result' => $this->implementation_result,

            'items' => $this->whenLoaded('items', fn (): array => $this->items->map(static fn ($item): array => [
                'id' => (string) $item->id,
                'entity_type' => $item->entity_type->value,
                'entity_id' => $item->entity_id,
                'action' => $item->action->value,
                'before_data' => $item->before_data,
                'proposed_data' => $item->proposed_data,
                'validation_snapshot' => $item->validation_snapshot,
                'resulting_entity_id' => $item->resulting_entity_id,
            ])->all()),

            'reviews' => $this->whenLoaded('reviews', fn (): array => $this->reviews->map(static fn ($review): array => [
                'id' => (string) $review->id,
                'action' => $review->action->value,
                'stage' => $review->stage,
                'comment' => $review->comment,
                'revision' => $review->revision,
                'reviewed_at' => $review->reviewed_at?->toIso8601String(),
                'reviewer' => $review->reviewer ? ['id' => $review->reviewer->id, 'name' => $review->reviewer->name] : null,
            ])->all()),

            'attachments' => $this->whenLoaded('attachments', fn (): array => $this->attachments->map(static fn ($attachment): array => [
                'id' => (string) $attachment->id,
                'document_type' => $attachment->document_type->value,
                'reference_no' => $attachment->reference_no,
                'document_date' => $attachment->document_date?->toDateString(),
                'original_name' => $attachment->original_name,
                'file_size' => $attachment->file_size,
                'uploaded_at' => $attachment->created_at?->toIso8601String(),
                'uploader' => $attachment->uploader ? ['id' => $attachment->uploader->id, 'name' => $attachment->uploader->name] : null,
            ])->all()),

            'history' => $this->whenLoaded('history', fn (): array => $this->history->map(static fn ($entry): array => [
                'id' => (string) $entry->id,
                'action' => $entry->action,
                'from_status' => $entry->from_status,
                'to_status' => $entry->to_status,
                'comment' => $entry->comment,
                'revision' => $entry->revision,
                'created_at' => $entry->created_at?->toIso8601String(),
                'actor' => $entry->actor ? ['id' => $entry->actor->id, 'name' => $entry->actor->name] : null,
            ])->all()),
        ];
    }
}

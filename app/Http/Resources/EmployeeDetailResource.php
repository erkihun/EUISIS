<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;

class EmployeeDetailResource extends EmployeeResource
{
    public function toArray(Request $request): array
    {
        $data = parent::toArray($request);

        $data['first_name'] = $this->first_name;
        $data['middle_name'] = $this->middle_name;
        $data['last_name'] = $this->last_name;
        // national_id is encrypted PII — only exposed to users with the
        // employees.viewPii permission (or Super Admin via Gate::before).
        $data['national_id'] = $request->user()?->can('employees.viewPii') ? $this->national_id : null;
        $data['photo_path'] = $this->photo_path;
        $data['photo_url'] = $this->photo_url;
        $data['date_of_birth'] = $this->date_of_birth?->toDateString();
        $data['gender'] = $this->gender;
        $data['data_quality_score'] = $this->data_quality_score;
        $data['assignments'] = $this->whenLoaded('assignments', fn (): array => $this->assignments->map(fn ($assignment): array => [
            'id' => $assignment->id,
            'assignment_status' => $assignment->assignment_status?->value ?? $assignment->assignment_status,
            'effective_from' => $assignment->effective_from?->toDateString(),
            'effective_to' => $assignment->effective_to?->toDateString(),
            'reason' => $assignment->reason,
            'organization' => $assignment->organization ? [
                'id' => $assignment->organization->id,
                'name_en' => $assignment->organization->name_en,
                'name_am' => $assignment->organization->name_am,
            ] : null,
            'position' => $assignment->position ? [
                'id' => $assignment->position->id,
                'title_en' => $assignment->position->title_en,
                'title_am' => $assignment->position->title_am,
            ] : null,
        ])->all());
        $data['duplicate_flags'] = $this->whenLoaded('employeeDuplicateFlags', fn (): array => $this->employeeDuplicateFlags->map(fn ($flag): array => [
            'id' => $flag->id,
            'risk_score' => $flag->risk_score,
            'status' => $flag->status,
            'matched_fields' => $flag->matched_fields,
            'matched_employee' => $flag->matchedEmployee ? [
                'id' => $flag->matchedEmployee->id,
                'employee_number' => $flag->matchedEmployee->employee_number,
                'full_name' => $flag->matchedEmployee->full_name,
            ] : null,
        ])->all());
        $data['documents'] = $this->whenLoaded('documents', fn (): array => $this->documents->map(fn ($document): array => [
            'id' => $document->id,
            'document_type' => $document->document_type,
            'storage_disk' => $document->storage_disk,
            'is_private' => $document->is_private,
            'created_at' => $document->created_at?->toAtomString(),
        ])->all());
        $data['transfers'] = $this->whenLoaded('transfers', fn (): array => $this->transfers->map(fn ($transfer): array => [
            'id' => $transfer->id,
            'status' => $transfer->status?->value ?? $transfer->status,
            'effective_date' => $transfer->effective_date?->toDateString(),
            'from_organization' => $transfer->fromOrganization ? [
                'id' => $transfer->fromOrganization->id,
                'name_en' => $transfer->fromOrganization->name_en,
                'name_am' => $transfer->fromOrganization->name_am,
            ] : null,
            'to_organization' => $transfer->toOrganization ? [
                'id' => $transfer->toOrganization->id,
                'name_en' => $transfer->toOrganization->name_en,
                'name_am' => $transfer->toOrganization->name_am,
            ] : null,
        ])->all());

        return $data;
    }
}

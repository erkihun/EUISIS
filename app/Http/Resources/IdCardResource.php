<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IdCardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'card_number' => $this->card_number,
            'status' => $this->status?->value,
            'token_version' => $this->token_version,
            'printed_at' => $this->printed_at?->toIso8601String(),
            'issued_at' => $this->issued_at?->toIso8601String(),
            'activated_at' => $this->activated_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'revoke_reason' => $this->revoke_reason,
            'notes' => $this->notes,
            'is_current' => $this->is_current,
            'previous_card_id' => $this->previous_card_id,
            'card_request_id' => $this->card_request_id,
            'created_at' => $this->created_at?->toIso8601String(),
            'employee' => $this->whenLoaded('employee', fn () => [
                'id' => $this->employee->id,
                'employee_number' => $this->employee->employee_number,
                'full_name' => $this->employee->full_name,
                'name_en' => $this->employee->name_en,
                'full_name_am' => $this->employee->metadata['name_am'] ?? null,
                'gender' => $this->employee->gender,
                'status' => $this->employee->status?->value,
                'photo_path' => $this->employee->photo_path,
                'photo_url' => $this->employee->photo_url,
                'current_assignment' => $this->employee->currentAssignment ? [
                    'organization' => $this->employee->currentAssignment->organization ? [
                        'id' => $this->employee->currentAssignment->organization->id,
                        'name_en' => $this->employee->currentAssignment->organization->name_en,
                        'name_am' => $this->employee->currentAssignment->organization->name_am,
                        'code' => $this->employee->currentAssignment->organization->code,
                        'logo_url' => $this->employee->currentAssignment->organization->logo_url,
                    ] : null,
                    'organization_unit' => $this->employee->currentAssignment->organizationUnit ? [
                        'id' => $this->employee->currentAssignment->organizationUnit->id,
                        'name_en' => $this->employee->currentAssignment->organizationUnit->name_en,
                        'name_am' => $this->employee->currentAssignment->organizationUnit->name_am,
                    ] : null,
                    'position' => $this->employee->currentAssignment->position ? [
                        'title_en' => $this->employee->currentAssignment->position->title_en,
                        'title_am' => $this->employee->currentAssignment->position->title_am,
                        'job_position_code' => $this->employee->currentAssignment->position->job_position_code,
                        'grade_level' => $this->employee->currentAssignment->position->grade_level,
                    ] : null,
                ] : null,
            ]),
            'qr_payload' => $user?->can('view', $this->resource) ? $this->qr_payload : null,
            'public_card_uuid' => $this->public_card_uuid,
            'qr_status' => $this->qr_status,
            'qr_issued_at' => $this->qr_issued_at?->toIso8601String(),
            'qr_rotated_at' => $this->qr_rotated_at?->toIso8601String(),
            'can' => $user ? [
                'view' => $user->can('view', $this->resource),
                'update' => $user->can('update', $this->resource),
                'print' => $user->can('print', $this->resource),
                'issue' => $user->can('issue', $this->resource),
                'activate' => $user->can('activate', $this->resource),
                'reportLost' => $user->can('reportLost', $this->resource),
                'reportDamaged' => $user->can('reportDamaged', $this->resource),
                'replace' => $user->can('replace', $this->resource),
                'revoke' => $user->can('revoke', $this->resource),
                'printAnytime' => $user->can('printAnytime', $this->resource),
                'exportPng' => $user->can('exportPng', $this->resource),
            ] : [],
        ];
    }
}

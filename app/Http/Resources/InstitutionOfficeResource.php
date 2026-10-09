<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\InstitutionOfficeLevel;
use App\Enums\InstitutionOfficeStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InstitutionOfficeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'institution_id' => $this->institution_id,
            'structural_organization_id' => $this->structural_organization_id,
            'institution' => $this->whenLoaded('institution', fn () => $this->institution ? [
                'id' => $this->institution->id,
                'name_en' => $this->institution->name_en,
                'name_am' => $this->institution->name_am,
                'code' => $this->institution->code,
            ] : null),
            'geographic_organization_id' => $this->geographic_organization_id,
            'structuralOrganization' => $this->whenLoaded('structuralOrganization', fn () => $this->structuralOrganization ? [
                'id' => $this->structuralOrganization->id,
                'name_en' => $this->structuralOrganization->name_en,
                'name_am' => $this->structuralOrganization->name_am,
                'code' => $this->structuralOrganization->code,
            ] : null),
            'geographicOrganization' => $this->whenLoaded('geographicOrganization', fn () => $this->geographicOrganization ? [
                'id' => $this->geographicOrganization->id,
                'name_en' => $this->geographicOrganization->name_en,
                'name_am' => $this->geographicOrganization->name_am,
                'code' => $this->geographicOrganization->code,
            ] : null),
            'parent_office_id' => $this->parent_office_id,
            'parentOffice' => $this->whenLoaded('parentOffice', fn () => $this->parentOffice ? [
                'id' => $this->parentOffice->id,
                'name_en' => $this->parentOffice->name_en,
                'office_code' => $this->parentOffice->office_code,
            ] : null),
            'office_level' => $this->office_level instanceof InstitutionOfficeLevel
                ? $this->office_level->value
                : $this->office_level,
            'office_code' => $this->office_code,
            'name_en' => $this->name_en,
            'name_am' => $this->name_am,
            'short_name_en' => $this->short_name_en,
            'short_name_am' => $this->short_name_am,
            'assigned_scope_type' => $this->assigned_scope_type,
            'is_head_office' => $this->is_head_office,
            'status' => $this->status instanceof InstitutionOfficeStatus
                ? $this->status->value
                : $this->status,
            'opened_on' => $this->opened_on?->toDateString(),
            'closed_on' => $this->closed_on?->toDateString(),
            'address_en' => $this->address_en,
            'address_am' => $this->address_am,
            'phone_number' => $this->phone_number,
            'email' => $this->email,
            'notes' => $this->notes,
            'metadata' => $this->metadata,
            'children_count' => $this->whenCounted('childOffices'),
            'children' => $this->whenLoaded('childOffices', fn () => self::collection($this->childOffices)),
            'can' => [
                'update' => $user?->can('update', $this->resource) ?? false,
                'delete' => $user?->can('delete', $this->resource) ?? false,
                'restore' => $user?->can('restore', $this->resource) ?? false,
                'move' => $user?->can('move', $this->resource) ?? false,
            ],
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}

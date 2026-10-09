<?php

declare(strict_types=1);

namespace App\Actions\Organizations;

use App\Actions\Audit\WriteAuditLogAction;
use App\Actions\CodeRules\GenerateCodeAction;
use App\Enums\AuditEventType;
use App\Enums\CodeRuleEntityType;
use App\Enums\OrganizationRelationshipType;
use App\Models\Organization;
use App\Models\OrganizationEdge;
use App\Models\OrganizationNameHistory;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

readonly class CreateOrganizationAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLogAction,
        private GenerateCodeAction $generateCodeAction,
    ) {}

    public function execute(array $attributes, User $actor): Organization
    {
        return DB::transaction(function () use ($attributes, $actor): Organization {
            $attributes['code'] = $this->generateCodeAction->execute(
                CodeRuleEntityType::Organization,
                [
                    'organization_type_id' => $attributes['organization_type_id'] ?? null,
                ],
                $actor,
                $attributes['code'] ?? null,
                'code',
            );

            $edgeAttributes = [
                'parent_organization_id' => $attributes['parent_organization_id'] ?? null,
                'hierarchy_version_id' => $attributes['hierarchy_version_id'] ?? null,
                'relationship_type' => $this->normalizeRelationshipType($attributes['relationship_type'] ?? null),
            ];

            unset($attributes['parent_organization_id'], $attributes['hierarchy_version_id'], $attributes['relationship_type']);

            // Logo is handled after create so we have the organization ID for the path.
            $logoFile = ($attributes['logo'] ?? null) instanceof UploadedFile ? $attributes['logo'] : null;
            unset($attributes['logo']);

            // Normalize hex colors to uppercase.
            foreach (['branding_primary_color', 'branding_secondary_color'] as $field) {
                if (! empty($attributes[$field])) {
                    $attributes[$field] = strtoupper($attributes[$field]);
                }
            }

            $organization = Organization::query()->create($attributes);

            if ($logoFile !== null) {
                $ext = $logoFile->getClientOriginalExtension();
                $path = $logoFile->storeAs(
                    "organizations/logos/{$organization->id}",
                    Str::uuid().'.'.$ext,
                    'public',
                );
                $organization->update(['logo_path' => $path]);

                $this->writeAuditLogAction->execute(
                    AuditEventType::OrganizationLogoUploaded,
                    $actor,
                    $organization,
                    $organization->id,
                    newValues: ['logo_path' => $path],
                );
            }

            OrganizationNameHistory::query()->create([
                'organization_id' => $organization->id,
                'name_en' => $organization->name_en,
                'name_am' => $organization->name_am,
                'effective_from' => $organization->effective_from ?? now()->toDateString(),
                'effective_to' => $organization->effective_to,
            ]);

            if ($edgeAttributes['parent_organization_id'] !== null && $edgeAttributes['hierarchy_version_id'] !== null) {
                OrganizationEdge::query()->create([
                    'hierarchy_version_id' => $edgeAttributes['hierarchy_version_id'],
                    'parent_organization_id' => $edgeAttributes['parent_organization_id'],
                    'child_organization_id' => $organization->id,
                    'relationship_type' => $edgeAttributes['relationship_type'],
                    'effective_from' => $organization->effective_from ?? now()->toDateString(),
                    'effective_to' => $organization->effective_to,
                ]);
            }

            $this->writeAuditLogAction->execute(
                AuditEventType::OrganizationCreated,
                $actor,
                $organization,
                $organization->id,
                newValues: $organization->fresh()->toArray(),
            );

            return $organization->fresh();
        });
    }

    private function normalizeRelationshipType(?string $relationshipType): ?string
    {
        return match ($relationshipType) {
            'administrative' => OrganizationRelationshipType::GeographicallyUnder->value,
            'technical' => OrganizationRelationshipType::Oversight->value,
            default => $relationshipType,
        };
    }
}

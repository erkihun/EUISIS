<?php

declare(strict_types=1);

namespace App\Services\OrganizationRelationships;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\InstitutionOfficeRelationship;
use App\Models\User;
use Illuminate\Http\Request;

readonly class InstitutionOfficeRelationshipService
{
    public function __construct(
        private RelationshipValidationService $validationService,
        private WriteAuditLogAction $writeAuditLog,
    ) {}

    public function create(array $attributes, ?User $user = null, ?Request $request = null): InstitutionOfficeRelationship
    {
        $attributes['created_by'] = $user?->id;
        $attributes['updated_by'] = $user?->id;
        $this->validationService->validateOfficeRelationship($attributes);

        $relationship = InstitutionOfficeRelationship::query()->create($attributes);

        $this->writeAuditLog->execute(AuditEventType::InstitutionOfficeRelationshipCreated, $user, $relationship, null, null, $relationship->toArray(), null, $request);

        return $relationship;
    }

    public function update(InstitutionOfficeRelationship $relationship, array $attributes, ?User $user = null, ?Request $request = null): InstitutionOfficeRelationship
    {
        $old = $relationship->toArray();
        $attributes = array_merge($relationship->only([
            'source_office_id',
            'target_type',
            'target_id',
            'relationship_type',
            'is_primary',
            'effective_from',
            'effective_to',
            'status',
        ]), $attributes, ['updated_by' => $user?->id]);

        $this->validationService->validateOfficeRelationship($attributes, $relationship);
        $relationship->update($attributes);

        $this->writeAuditLog->execute(AuditEventType::InstitutionOfficeRelationshipUpdated, $user, $relationship, null, $old, $relationship->fresh()?->toArray(), null, $request);

        return $relationship->refresh();
    }
}

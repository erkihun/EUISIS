<?php

declare(strict_types=1);

namespace App\Services\OrganizationRelationships;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\OrganizationUnitRelationship;
use App\Models\User;
use Illuminate\Http\Request;

readonly class OrganizationUnitRelationshipService
{
    public function __construct(
        private RelationshipValidationService $validationService,
        private WriteAuditLogAction $writeAuditLog,
    ) {}

    public function create(array $attributes, ?User $user = null, ?Request $request = null): OrganizationUnitRelationship
    {
        $attributes['created_by'] = $user?->id;
        $attributes['updated_by'] = $user?->id;
        $this->validationService->validateUnitRelationship($attributes);

        $relationship = OrganizationUnitRelationship::query()->create($attributes);

        $this->writeAuditLog->execute(AuditEventType::OrganizationUnitRelationshipCreated, $user, $relationship, null, null, $relationship->toArray(), null, $request);

        return $relationship;
    }

    public function update(OrganizationUnitRelationship $relationship, array $attributes, ?User $user = null, ?Request $request = null): OrganizationUnitRelationship
    {
        $old = $relationship->toArray();
        $attributes = array_merge($relationship->only([
            'source_unit_id',
            'target_type',
            'target_id',
            'relationship_type',
            'is_primary',
            'effective_from',
            'effective_to',
            'status',
        ]), $attributes, ['updated_by' => $user?->id]);

        $this->validationService->validateUnitRelationship($attributes, $relationship);
        $relationship->update($attributes);

        $this->writeAuditLog->execute(AuditEventType::OrganizationUnitRelationshipUpdated, $user, $relationship, null, $old, $relationship->fresh()?->toArray(), null, $request);

        return $relationship->refresh();
    }
}

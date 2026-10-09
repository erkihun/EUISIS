<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrganizationUnitRelationshipRequest;
use App\Http\Requests\UpdateOrganizationUnitRelationshipRequest;
use App\Http\Resources\OrganizationUnitRelationshipResource;
use App\Models\OrganizationUnit;
use App\Models\OrganizationUnitRelationship;
use App\Services\OrganizationRelationships\OrganizationUnitRelationshipService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class OrganizationUnitRelationshipController extends Controller
{
    public function index(OrganizationUnit $organizationUnit)
    {
        $this->authorize('viewAny', OrganizationUnitRelationship::class);

        $relationships = $organizationUnit->relationships()
            ->latest()
            ->paginate(25);

        return OrganizationUnitRelationshipResource::collection($relationships);
    }

    public function store(
        StoreOrganizationUnitRelationshipRequest $request,
        OrganizationUnit $organizationUnit,
        OrganizationUnitRelationshipService $service,
    ): RedirectResponse {
        $service->create([
            ...$request->payload(),
            'source_unit_id' => $organizationUnit->id,
        ], $request->user(), $request);

        return to_route('organization-units.show', $organizationUnit)
            ->with('flash', ['message' => __('relationships.messages.created'), 'type' => 'success']);
    }

    public function show(OrganizationUnit $organizationUnit, OrganizationUnitRelationship $relationship)
    {
        $this->authorize('view', $relationship);
        $this->ensureBelongsToUnit($organizationUnit, $relationship);

        return new OrganizationUnitRelationshipResource($relationship);
    }

    public function update(
        UpdateOrganizationUnitRelationshipRequest $request,
        OrganizationUnit $organizationUnit,
        OrganizationUnitRelationship $relationship,
        OrganizationUnitRelationshipService $service,
    ): RedirectResponse {
        $this->ensureBelongsToUnit($organizationUnit, $relationship);
        $service->update($relationship, $request->payload(), $request->user(), $request);

        return to_route('organization-units.show', $organizationUnit)
            ->with('flash', ['message' => __('relationships.messages.updated'), 'type' => 'success']);
    }

    public function destroy(
        Request $request,
        OrganizationUnit $organizationUnit,
        OrganizationUnitRelationship $relationship,
        WriteAuditLogAction $writeAuditLog,
    ): RedirectResponse {
        $this->authorize('delete', $relationship);
        $this->ensureBelongsToUnit($organizationUnit, $relationship);
        $old = $relationship->toArray();
        $relationship->delete();
        $writeAuditLog->execute(AuditEventType::OrganizationUnitRelationshipDeleted, $request->user(), $relationship, null, $old, null, null, $request);

        return to_route('organization-units.show', $organizationUnit)
            ->with('flash', ['message' => __('relationships.messages.deleted'), 'type' => 'success']);
    }

    public function restore(
        Request $request,
        OrganizationUnit $organizationUnit,
        string $relationship,
        WriteAuditLogAction $writeAuditLog,
    ): RedirectResponse {
        $relationshipModel = OrganizationUnitRelationship::withTrashed()->findOrFail($relationship);
        $this->authorize('restore', $relationshipModel);
        $this->ensureBelongsToUnit($organizationUnit, $relationshipModel);
        $relationshipModel->restore();
        $writeAuditLog->execute(AuditEventType::OrganizationUnitRelationshipRestored, $request->user(), $relationshipModel, null, null, $relationshipModel->toArray(), null, $request);

        return to_route('organization-units.show', $organizationUnit)
            ->with('flash', ['message' => __('relationships.messages.restored'), 'type' => 'success']);
    }

    private function ensureBelongsToUnit(OrganizationUnit $unit, OrganizationUnitRelationship $relationship): void
    {
        abort_unless($relationship->source_unit_id === $unit->id, 404);
    }
}

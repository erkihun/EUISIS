<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreInstitutionOfficeRelationshipRequest;
use App\Http\Requests\UpdateInstitutionOfficeRelationshipRequest;
use App\Http\Resources\InstitutionOfficeRelationshipResource;
use App\Models\InstitutionOffice;
use App\Models\InstitutionOfficeRelationship;
use App\Services\OrganizationRelationships\InstitutionOfficeRelationshipService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class InstitutionOfficeRelationshipController extends Controller
{
    public function index(InstitutionOffice $institutionOffice)
    {
        $this->authorize('viewAny', InstitutionOfficeRelationship::class);

        $relationships = $institutionOffice->relationships()
            ->latest()
            ->paginate(25);

        return InstitutionOfficeRelationshipResource::collection($relationships);
    }

    public function store(
        StoreInstitutionOfficeRelationshipRequest $request,
        InstitutionOffice $institutionOffice,
        InstitutionOfficeRelationshipService $service,
    ): RedirectResponse {
        $service->create([
            ...$request->payload(),
            'source_office_id' => $institutionOffice->id,
        ], $request->user(), $request);

        return to_route('institution-offices.show', $institutionOffice)
            ->with('flash', ['message' => __('relationships.messages.created'), 'type' => 'success']);
    }

    public function show(InstitutionOffice $institutionOffice, InstitutionOfficeRelationship $relationship)
    {
        $this->authorize('view', $relationship);
        $this->ensureBelongsToOffice($institutionOffice, $relationship);

        return new InstitutionOfficeRelationshipResource($relationship);
    }

    public function update(
        UpdateInstitutionOfficeRelationshipRequest $request,
        InstitutionOffice $institutionOffice,
        InstitutionOfficeRelationship $relationship,
        InstitutionOfficeRelationshipService $service,
    ): RedirectResponse {
        $this->ensureBelongsToOffice($institutionOffice, $relationship);
        $service->update($relationship, $request->payload(), $request->user(), $request);

        return to_route('institution-offices.show', $institutionOffice)
            ->with('flash', ['message' => __('relationships.messages.updated'), 'type' => 'success']);
    }

    public function destroy(
        Request $request,
        InstitutionOffice $institutionOffice,
        InstitutionOfficeRelationship $relationship,
        WriteAuditLogAction $writeAuditLog,
    ): RedirectResponse {
        $this->authorize('delete', $relationship);
        $this->ensureBelongsToOffice($institutionOffice, $relationship);
        $old = $relationship->toArray();
        $relationship->delete();
        $writeAuditLog->execute(AuditEventType::InstitutionOfficeRelationshipDeleted, $request->user(), $relationship, null, $old, null, null, $request);

        return to_route('institution-offices.show', $institutionOffice)
            ->with('flash', ['message' => __('relationships.messages.deleted'), 'type' => 'success']);
    }

    public function restore(
        Request $request,
        InstitutionOffice $institutionOffice,
        string $relationship,
        WriteAuditLogAction $writeAuditLog,
    ): RedirectResponse {
        $relationshipModel = InstitutionOfficeRelationship::withTrashed()->findOrFail($relationship);
        $this->authorize('restore', $relationshipModel);
        $this->ensureBelongsToOffice($institutionOffice, $relationshipModel);
        $relationshipModel->restore();
        $writeAuditLog->execute(AuditEventType::InstitutionOfficeRelationshipRestored, $request->user(), $relationshipModel, null, null, $relationshipModel->toArray(), null, $request);

        return to_route('institution-offices.show', $institutionOffice)
            ->with('flash', ['message' => __('relationships.messages.restored'), 'type' => 'success']);
    }

    private function ensureBelongsToOffice(InstitutionOffice $office, InstitutionOfficeRelationship $relationship): void
    {
        abort_unless($relationship->source_office_id === $office->id, 404);
    }
}

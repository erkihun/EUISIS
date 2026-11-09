<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Organizations\CreateOrganizationEdgeAction;
use App\Actions\Organizations\DeleteOrganizationEdgeAction;
use App\Actions\Organizations\UpdateOrganizationEdgeAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\DeleteOrganizationEdgeRequest;
use App\Http\Requests\StoreOrganizationEdgeRequest;
use App\Http\Requests\UpdateOrganizationEdgeRequest;
use App\Models\HierarchyVersion;
use App\Models\OrganizationEdge;
use Illuminate\Http\RedirectResponse;

class OrganizationEdgeController extends Controller
{
    public function store(
        StoreOrganizationEdgeRequest $request,
        HierarchyVersion $hierarchyVersion,
        CreateOrganizationEdgeAction $createOrganizationEdgeAction,
    ): RedirectResponse {
        $createOrganizationEdgeAction->execute($hierarchyVersion, $request->validated(), $request->user(), $request);

        return back()
            ->with('flash', ['message' => __('hierarchy-versions.edge_created_successfully'), 'type' => 'success']);
    }

    public function update(
        UpdateOrganizationEdgeRequest $request,
        HierarchyVersion $hierarchyVersion,
        OrganizationEdge $organizationEdge,
        UpdateOrganizationEdgeAction $updateOrganizationEdgeAction,
    ): RedirectResponse {
        abort_unless($organizationEdge->hierarchy_version_id === $hierarchyVersion->id, 404);

        $updateOrganizationEdgeAction->execute($hierarchyVersion, $organizationEdge, $request->validated(), $request->user(), $request);

        return back()
            ->with('flash', ['message' => __('hierarchy-versions.edge_updated_successfully'), 'type' => 'success']);
    }

    public function destroy(
        DeleteOrganizationEdgeRequest $request,
        HierarchyVersion $hierarchyVersion,
        OrganizationEdge $organizationEdge,
        DeleteOrganizationEdgeAction $deleteOrganizationEdgeAction,
    ): RedirectResponse {
        abort_unless($organizationEdge->hierarchy_version_id === $hierarchyVersion->id, 404);

        $deleteOrganizationEdgeAction->execute($hierarchyVersion, $organizationEdge, $request->user(), $request);

        return back()
            ->with('flash', ['message' => __('hierarchy-versions.edge_removed_successfully'), 'type' => 'success']);
    }
}

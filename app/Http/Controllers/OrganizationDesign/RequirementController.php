<?php

declare(strict_types=1);

namespace App\Http\Controllers\OrganizationDesign;

use App\Http\Controllers\Controller;
use App\Http\Requests\OrganizationDesign\RequirementSyncRequest;
use App\Services\OrganizationDesign\WorkRequirementService;
use Illuminate\Http\RedirectResponse;

/** The occupation / competency / qualification requirements of an activity or task. */
class RequirementController extends Controller
{
    public function __construct(private readonly WorkRequirementService $requirements) {}

    public function sync(RequirementSyncRequest $request): RedirectResponse
    {
        $this->requirements->sync($request->user(), $request->subject(), $request->validated());

        return back()->with('flash', ['message' => __('organization-design.requirements_saved'), 'type' => 'success']);
    }
}

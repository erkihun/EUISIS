<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Enums\GrievanceOriginLevel;
use App\Http\Controllers\Controller;
use App\Models\Grievance;
use App\Models\GrievanceSlaRule;
use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GrievanceSlaRuleController extends Controller
{
    public function index(): Response
    {
        $this->authorize('manage', Grievance::class);

        return Inertia::render('GrievanceSlaRules/Index', [
            'rules' => GrievanceSlaRule::query()->with('organization')->orderBy('origin_level')->get(),
            'organizations' => Organization::query()->where('status', 'active')->orderBy('name_en')->get(['id', 'name_en', 'name_am']),
            'originLevels' => array_column(GrievanceOriginLevel::cases(), 'value'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('manage', Grievance::class);

        $data = $request->validate([
            'organization_id' => ['nullable', 'uuid', 'exists:organizations,id'],
            'origin_level' => ['required', 'string', 'in:'.implode(',', array_column(GrievanceOriginLevel::cases(), 'value'))],
            'escalation_from_type' => ['required', 'string', 'max:100'],
            'escalation_to_type' => ['required', 'string', 'max:100'],
            'working_days_limit' => ['required', 'integer', 'min:1', 'max:30'],
        ]);

        GrievanceSlaRule::query()->create($data);

        return back()->with('flash', ['message' => __('grievances.slaRuleCreated'), 'type' => 'success']);
    }

    public function update(Request $request, GrievanceSlaRule $grievanceSlaRule): RedirectResponse
    {
        $this->authorize('manage', Grievance::class);

        $data = $request->validate([
            'working_days_limit' => ['required', 'integer', 'min:1', 'max:30'],
            'status' => ['required', 'string', 'in:active,inactive'],
        ]);

        $grievanceSlaRule->update($data);

        return back()->with('flash', ['message' => __('grievances.slaRuleUpdated'), 'type' => 'success']);
    }

    public function destroy(GrievanceSlaRule $grievanceSlaRule): RedirectResponse
    {
        $this->authorize('manage', Grievance::class);

        $grievanceSlaRule->delete();

        return back()->with('flash', ['message' => __('grievances.slaRuleDeleted'), 'type' => 'success']);
    }
}

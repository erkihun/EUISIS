<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Grievances\ApproveGrievanceResponseAction;
use App\Actions\Grievances\AssignGrievanceAction;
use App\Actions\Grievances\CheckGrievanceRequirementAction;
use App\Actions\Grievances\CompileGrievanceResponseAction;
use App\Actions\Grievances\RejectGrievanceResponseAction;
use App\Actions\Grievances\SubmitGrievanceAction;
use App\Enums\GrievanceOriginLevel;
use App\Enums\GrievanceStatus;
use App\Http\Controllers\Controller;
use App\Models\Grievance;
use App\Models\GrievanceCategory;
use App\Models\GrievanceCommittee;
use App\Models\Organization;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class GrievanceController extends Controller
{
    public function index(Request $request, OrganizationScopeService $scopeService): Response
    {
        $this->authorize('viewAny', Grievance::class);

        $user = Auth::user();

        $query = Grievance::query()
            ->with(['category', 'organization', 'organizationUnit', 'employee'])
            ->latest();

        if (! $user->hasAnyRole(['Super Admin', 'City Admin'])) {
            $scopedIds = $scopeService->accessibleOrganizationIds($user);
            $query->whereIn('organization_id', $scopedIds);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->filled('origin_level')) {
            $query->where('origin_level', $request->string('origin_level'));
        }

        return Inertia::render('Grievances/Index', [
            'grievances' => $query->paginate(20)->withQueryString(),
            'filters' => $request->only('status', 'origin_level'),
            'statuses' => array_column(GrievanceStatus::cases(), 'value'),
            'originLevels' => array_column(GrievanceOriginLevel::cases(), 'value'),
            'can' => ['create' => true],
        ]);
    }

    public function myGrievances(Request $request): Response
    {
        $user = Auth::user();

        $query = Grievance::query()
            ->with(['category', 'organization', 'decisionLetter'])
            ->where('submitted_by_user_id', $user->id)
            ->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        return Inertia::render('Grievances/MyGrievances', [
            'grievances' => $query->paginate(20)->withQueryString(),
            'filters' => $request->only('status'),
            'statuses' => array_column(GrievanceStatus::cases(), 'value'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Grievances/Create', [
            'categories' => GrievanceCategory::query()->where('is_active', true)->orderBy('name_en')->get(['id', 'name_en', 'name_am']),
            'organizations' => Organization::query()->where('status', 'active')->orderBy('name_en')->get(['id', 'name_en', 'name_am']),
            'originLevels' => array_column(GrievanceOriginLevel::cases(), 'value'),
        ]);
    }

    public function store(Request $request, SubmitGrievanceAction $action): RedirectResponse
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'category_id' => ['required', 'uuid', 'exists:grievance_categories,id'],
            'origin_level' => ['required', 'string', 'in:'.implode(',', array_column(GrievanceOriginLevel::cases(), 'value'))],
            'organization_id' => ['required', 'uuid', 'exists:organizations,id'],
            'organization_unit_id' => ['nullable', 'uuid', 'exists:organization_units,id'],
        ]);

        $grievance = $action->execute(Auth::user(), $data);

        return to_route('grievances.show', $grievance)
            ->with('flash', ['message' => __('grievances.submitted'), 'type' => 'success']);
    }

    public function show(Grievance $grievance): Response
    {
        $this->authorize('view', $grievance);

        $user = Auth::user();
        $grievance->load([
            'category', 'organization', 'organizationUnit', 'employee',
            'submittedByUser', 'currentAssignment.committee',
            'responses.draftedByEmployee', 'responses.compiledByEmployee',
            'responses.approvedByUser', 'responses.rejectedByUser',
            'escalations', 'decisionLetter', 'tribunalCase',
        ]);

        $committees = GrievanceCommittee::query()
            ->where('organization_id', $grievance->organization_id)
            ->where('status', 'active')
            ->get(['id', 'name_en', 'committee_type']);

        return Inertia::render('Grievances/Show', [
            'grievance' => $grievance,
            'committees' => $committees,
            'can' => [
                'assign' => $user->can('assign', $grievance),
                'checkRequirement' => $user->can('checkRequirement', $grievance),
                'draftResponse' => $user->can('draftResponse', $grievance),
                'compileResponse' => $user->can('compileResponse', $grievance),
                'approveResponse' => $user->can('approveResponse', $grievance),
                'generateLetter' => $user->can('generateLetter', $grievance),
            ],
        ]);
    }

    public function assign(Request $request, Grievance $grievance, AssignGrievanceAction $action): RedirectResponse
    {
        $this->authorize('assign', $grievance);

        $data = $request->validate([
            'committee_id' => ['required', 'uuid', 'exists:grievance_committees,id'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $committee = GrievanceCommittee::query()->findOrFail($data['committee_id']);
        $action->execute($grievance, $committee, Auth::user(), $data['notes'] ?? null);

        return to_route('grievances.show', $grievance)
            ->with('flash', ['message' => __('grievances.assigned'), 'type' => 'success']);
    }

    public function checkRequirement(Request $request, Grievance $grievance, CheckGrievanceRequirementAction $action): RedirectResponse
    {
        $this->authorize('checkRequirement', $grievance);

        $data = $request->validate([
            'fulfilled' => ['required', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $action->execute($grievance, Auth::user(), (bool) $data['fulfilled'], $data['notes'] ?? null);

        return to_route('grievances.show', $grievance)
            ->with('flash', ['message' => __('grievances.requirementChecked'), 'type' => 'success']);
    }

    public function compileResponse(Request $request, Grievance $grievance, CompileGrievanceResponseAction $action): RedirectResponse
    {
        $this->authorize('compileResponse', $grievance);

        $data = $request->validate([
            'response_body_en' => ['required', 'string'],
            'response_body_am' => ['nullable', 'string'],
        ]);

        $action->execute($grievance, Auth::user(), $data);

        return to_route('grievances.show', $grievance)
            ->with('flash', ['message' => __('grievances.responseCompiled'), 'type' => 'success']);
    }

    public function approveResponse(Grievance $grievance, ApproveGrievanceResponseAction $action): RedirectResponse
    {
        $this->authorize('approveResponse', $grievance);

        $action->execute($grievance, Auth::user());

        return to_route('grievances.show', $grievance)
            ->with('flash', ['message' => __('grievances.responseApproved'), 'type' => 'success']);
    }

    public function rejectResponse(Request $request, Grievance $grievance, RejectGrievanceResponseAction $action): RedirectResponse
    {
        $this->authorize('approveResponse', $grievance);

        $data = $request->validate([
            'rejection_reason' => ['required', 'string', 'max:1000'],
        ]);

        $action->execute($grievance, Auth::user(), $data['rejection_reason']);

        return to_route('grievances.show', $grievance)
            ->with('flash', ['message' => __('grievances.responseRejected'), 'type' => 'success']);
    }

    public function downloadLetter(Grievance $grievance): \Illuminate\Http\Response|RedirectResponse
    {
        $this->authorize('view', $grievance);

        $letter = $grievance->decisionLetter;

        if ($letter === null || $letter->file_path === null) {
            return to_route('grievances.show', $grievance)
                ->with('flash', ['message' => __('grievances.letterNotFound'), 'type' => 'error']);
        }

        $letter->update([
            'downloaded_at' => now(),
            'downloaded_by_user_id' => Auth::id(),
        ]);

        return response()->download(
            storage_path('app/'.$letter->file_path),
            $letter->letter_reference.'.pdf',
        );
    }
}

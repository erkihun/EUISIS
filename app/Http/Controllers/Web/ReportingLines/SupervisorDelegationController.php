<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\ReportingLines;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReportingLines\RevokeTemporaryAuthorityRequest;
use App\Http\Requests\ReportingLines\StoreSupervisorDelegationRequest;
use App\Models\Organization;
use App\Models\SupervisorDelegation;
use App\Services\OrganizationScope\OrganizationScopeService;
use App\Services\ReportingLines\ReportingLinePresenter;
use App\Services\ReportingLines\SupervisorDelegationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Supervisor delegations (ADR 0025), administered per organization in scope. A supervisor's own delegations live on My Team. */
class SupervisorDelegationController extends Controller
{
    public function __construct(
        private readonly OrganizationScopeService $scope,
        private readonly SupervisorDelegationService $service,
        private readonly ReportingLinePresenter $presenter,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', SupervisorDelegation::class);
        $user = $request->user();
        if (! $user->can('reporting-lines.view_any') && ! $user->can('reporting-lines.manage')) {
            abort(403);
        }

        $organizations = $this->scope->applyOrganizationScope(Organization::query(), $user, 'id')
            ->where('status', 'active')->orderBy('name_en')->get(['id', 'name_en', 'name_am']);
        $selectedId = $request->string('organization_id')->toString() ?: ($organizations->first()?->id);
        $status = $request->string('status')->toString();

        $page = SupervisorDelegation::query()
            ->whereIn('organization_id', $organizations->pluck('id'))
            ->when($selectedId, fn ($q) => $q->where('organization_id', $selectedId))
            ->when(in_array($status, ['active', 'revoked'], true), fn ($q) => $q->where('status', $status))
            ->with(['delegator', 'delegate', 'organization', 'authorizer', 'revoker'])
            ->orderByDesc('starts_on')
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('ReportingLines/Delegations', [
            'organizations' => $organizations->map(fn (Organization $o): array => ['id' => (string) $o->id, 'name_en' => $o->name_en, 'name_am' => $o->name_am])->values()->all(),
            'filters' => ['organization_id' => $selectedId, 'status' => $status],
            'delegations' => array_map(fn (SupervisorDelegation $d): array => $this->presenter->delegation($d), $page->items()),
            'meta' => $this->presenter->meta($page),
            'actions' => $this->presenter->actionOptions(),
            'can' => ['manage' => $user->can('reporting-lines.manage')],
        ]);
    }

    public function store(StoreSupervisorDelegationRequest $request): RedirectResponse
    {
        $delegator = $request->delegator();
        abort_if($delegator === null, 422);
        $delegation = $this->service->delegate($request->user(), $delegator, $request->validated());

        return redirect()
            ->route('reporting-lines.delegations.index', ['organization_id' => $delegation->organization_id])
            ->with('success', __('reporting-lines.flash.delegation_created'));
    }

    public function revoke(RevokeTemporaryAuthorityRequest $request, SupervisorDelegation $supervisorDelegation): RedirectResponse
    {
        $this->authorize('revoke', $supervisorDelegation);
        $this->service->revoke($request->user(), $supervisorDelegation, $request->string('reason')->toString());

        return back()->with('success', __('reporting-lines.flash.delegation_revoked'));
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\ReportingLines;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReportingLines\RevokeTemporaryAuthorityRequest;
use App\Http\Requests\ReportingLines\StoreActingAppointmentRequest;
use App\Models\ActingAppointment;
use App\Models\Organization;
use App\Services\OrganizationScope\OrganizationScopeService;
use App\Services\ReportingLines\ActingAppointmentService;
use App\Services\ReportingLines\ReportingLinePresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Acting appointments (ADR 0025): listed per organization in scope, created and revoked by reporting-lines.manage. */
class ActingAppointmentController extends Controller
{
    public function __construct(
        private readonly OrganizationScopeService $scope,
        private readonly ActingAppointmentService $service,
        private readonly ReportingLinePresenter $presenter,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', ActingAppointment::class);
        $user = $request->user();

        $organizations = $this->scope->applyOrganizationScope(Organization::query(), $user, 'id')
            ->where('status', 'active')->orderBy('name_en')->get(['id', 'name_en', 'name_am']);
        $selectedId = $request->string('organization_id')->toString() ?: ($organizations->first()?->id);
        $status = $request->string('status')->toString();

        $query = ActingAppointment::query()
            ->whereIn('organization_id', $organizations->pluck('id'))
            ->when($selectedId, fn ($q) => $q->where('organization_id', $selectedId))
            ->when(in_array($status, ['active', 'revoked'], true), fn ($q) => $q->where('status', $status))
            ->with(['position.organizationUnit', 'employee', 'organization', 'authorizer', 'revoker'])
            ->orderByDesc('starts_on')
            ->orderByDesc('created_at');
        $page = $query->paginate(25)->withQueryString();

        return Inertia::render('ReportingLines/ActingAppointments', [
            'organizations' => $organizations->map(fn (Organization $o): array => ['id' => (string) $o->id, 'name_en' => $o->name_en, 'name_am' => $o->name_am])->values()->all(),
            'filters' => ['organization_id' => $selectedId, 'status' => $status],
            'appointments' => array_map(fn (ActingAppointment $a): array => $this->presenter->actingAppointment($a), $page->items()),
            'meta' => $this->presenter->meta($page),
            'can' => [
                'create' => $user->can('reporting-lines.manage'),
                'manage' => $user->can('reporting-lines.manage'),
            ],
        ]);
    }

    public function store(StoreActingAppointmentRequest $request): RedirectResponse
    {
        $appointment = $this->service->appoint($request->user(), $request->validated());

        return redirect()
            ->route('reporting-lines.acting.index', ['organization_id' => $appointment->organization_id])
            ->with('success', __('reporting-lines.flash.acting_created'));
    }

    public function revoke(RevokeTemporaryAuthorityRequest $request, ActingAppointment $actingAppointment): RedirectResponse
    {
        $this->authorize('revoke', $actingAppointment);
        $this->service->revoke($request->user(), $actingAppointment, $request->string('reason')->toString());

        return back()->with('success', __('reporting-lines.flash.acting_revoked'));
    }
}

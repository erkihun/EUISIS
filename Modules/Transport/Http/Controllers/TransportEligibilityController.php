<?php

declare(strict_types=1);

namespace Modules\Transport\Http\Controllers;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Providers\Contracts\ProviderDirectory;
use Modules\Transport\Domain\Models\TransportContract;
use Modules\Transport\Domain\Models\TransportEligibility;
use Modules\Transport\Http\Requests\StoreTransportEligibilityRequest;
use Modules\Transport\Http\Resources\TransportEligibilityResource;

/** Who qualifies for transport, by employee or by office, within the viewer's organization scope. */
class TransportEligibilityController extends Controller
{
    public function index(Request $request, OrganizationScopeService $scope): Response
    {
        $user = $request->user();
        abort_unless($user?->can('transport-eligibility.viewAny'), 403);

        $organizations = $scope->isUnrestricted($user) ? null : array_map('strval', $scope->allowedOrganizationIds($user));
        $inScope = $scope->employeeIdsInScope($user);

        $grants = TransportEligibility::query()->with(['employee', 'organization', 'provider', 'authorizedBy'])
            ->when($organizations !== null, fn ($q) => $q->where(fn ($q) => $q->whereIn('organization_id', $organizations)->orWhereIn('employee_id', $inScope)))
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->subject_type, fn ($q, $s) => $q->where('subject_type', $s))
            ->latest()->paginate(25)->withQueryString();

        return Inertia::render('Transport/Eligibility/Index', [
            'grants' => TransportEligibilityResource::collection($grants)->response()->getData(true),
            'filters' => $request->only('status', 'subject_type'),
            'can' => ['create' => $user->can('transport-eligibility.create'), 'update' => $user->can('transport-eligibility.update')],
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()?->can('transport-eligibility.create'), 403);

        return Inertia::render('Transport/Eligibility/Create', $this->formPayload());
    }

    public function store(StoreTransportEligibilityRequest $request, WriteAuditLogAction $audit): RedirectResponse
    {
        $grant = TransportEligibility::query()->create($this->clean($request->validated()) + ['authorized_by' => $request->user()?->id, 'created_by' => $request->user()?->id]);
        $audit->execute(AuditEventType::TransportEligibilityChanged, $request->user(), $grant, $grant->organization_id, newValues: ['action' => 'created'] + $grant->only(['subject_type', 'employee_id', 'organization_id', 'scope_type', 'status', 'effective_from', 'effective_to', 'authorization_reference']), request: $request);

        return to_route('transport.eligibility.index')->with('flash', ['type' => 'success', 'message' => __('transport.eligibility_saved')]);
    }

    public function edit(Request $request, TransportEligibility $eligibility, OrganizationScopeService $scope): Response
    {
        abort_unless($request->user()?->can('transport-eligibility.update'), 403);
        $this->guardScope($request, $eligibility, $scope);

        return Inertia::render('Transport/Eligibility/Edit', ['grant' => (new TransportEligibilityResource($eligibility->load(['employee', 'organization', 'provider'])))->resolve(), ...$this->formPayload()]);
    }

    public function update(StoreTransportEligibilityRequest $request, TransportEligibility $eligibility, OrganizationScopeService $scope, WriteAuditLogAction $audit): RedirectResponse
    {
        $this->guardScope($request, $eligibility, $scope);
        $before = $eligibility->only(['status', 'effective_from', 'effective_to', 'scope_type', 'provider_id']);
        $eligibility->update($this->clean($request->validated()) + ['updated_by' => $request->user()?->id]);
        $audit->execute(AuditEventType::TransportEligibilityChanged, $request->user(), $eligibility, $eligibility->organization_id, oldValues: $before, newValues: ['action' => 'updated'] + $eligibility->only(['status', 'effective_from', 'effective_to', 'scope_type', 'provider_id']), request: $request);

        return to_route('transport.eligibility.index')->with('flash', ['type' => 'success', 'message' => __('transport.eligibility_saved')]);
    }

    /** @param  array<string, mixed>  $data @return array<string, mixed> */
    private function clean(array $data): array
    {
        if ($data['subject_type'] === 'employee') {
            $data['organization_id'] = null;
            $data['scope_type'] = 'self';
        } else {
            $data['employee_id'] = null;
        }

        return $data;
    }

    private function guardScope(Request $request, TransportEligibility $eligibility, OrganizationScopeService $scope): void
    {
        if ($eligibility->organization_id !== null) {
            abort_unless($scope->canAccessOrganization($request->user(), $eligibility->organization_id), 403);
        }
        if ($eligibility->employee_id !== null) {
            abort_unless($scope->canAccessEmployee($request->user(), $eligibility->employee()->with('currentAssignment')->firstOrFail()), 403);
        }
    }

    /** @return array<string, mixed> */
    private function formPayload(): array
    {
        return [
            'today' => today()->toDateString(),
            'providers' => app(ProviderDirectory::class)->offering('transport'),
            'contracts' => TransportContract::query()->where('status', 'active')->orderBy('contract_reference')->get(['id', 'contract_reference', 'version_no', 'provider_id', 'organization_id']),
        ];
    }
}

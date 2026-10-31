<?php

declare(strict_types=1);

namespace Modules\Transport\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\OrganizationScope\OrganizationScopeService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Providers\Contracts\ProviderDirectory;
use Modules\Transport\Application\Services\Settlement\TransportContractService;
use Modules\Transport\Domain\Enums\TransportSettlementModel;
use Modules\Transport\Domain\Enums\TransportSettlementPeriod;
use Modules\Transport\Domain\Models\TransportContract;
use Modules\Transport\Http\Requests\StoreTransportContractRequest;
use Modules\Transport\Http\Resources\TransportContractResource;
use Modules\Transport\Http\Resources\TransportSettlementResource;

/** Versioned contracts and their approval; agreements are private files. */
class TransportContractController extends Controller
{
    public function index(Request $request, OrganizationScopeService $scope): Response
    {
        $user = $request->user();
        abort_unless($user?->can('transport-contracts.viewAny'), 403);
        $organizations = $scope->isUnrestricted($user) ? null : array_map('strval', $scope->allowedOrganizationIds($user));

        $contracts = TransportContract::query()->with(['provider', 'organization'])
            ->when($organizations !== null, fn ($q) => $q->where(fn ($q) => $q->whereNull('organization_id')->orWhereIn('organization_id', $organizations)))
            ->when($request->provider_id, fn ($q, $id) => $q->where('provider_id', $id))
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->orderBy('contract_reference')->orderByDesc('version_no')->paginate(25)->withQueryString();

        return Inertia::render('Transport/Contracts/Index', [
            'contracts' => TransportContractResource::collection($contracts)->response()->getData(true),
            'filters' => $request->only('provider_id', 'status'),
            'providers' => $this->providers(),
            'can' => ['create' => $user->can('transport-contracts.create'), 'update' => $user->can('transport-contracts.update')],
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()?->can('transport-contracts.create'), 403);

        return Inertia::render('Transport/Contracts/Create', $this->formPayload());
    }

    public function store(StoreTransportContractRequest $request, TransportContractService $contracts): RedirectResponse
    {
        $data = $request->safe()->except(['agreement']);
        if ($request->hasFile('agreement')) {
            $data['agreement_path'] = $request->file('agreement')->store('transport/contracts', 'local');
        }

        try {
            $contract = $contracts->createDraft($data, $request->user(), $request);
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['terms' => $e->getMessage()]);
        }

        return to_route('transport.contracts.show', $contract)->with('flash', ['type' => 'success', 'message' => __('transport.contract_saved')]);
    }

    public function show(Request $request, TransportContract $contract, OrganizationScopeService $scope): Response
    {
        $user = $request->user();
        abort_unless($user?->can('transport-contracts.view'), 403);
        $this->guardScope($request, $contract, $scope);

        return Inertia::render('Transport/Contracts/Show', [
            'contract' => (new TransportContractResource($contract->load(['provider', 'organization'])))->resolve(),
            'versions' => TransportContractResource::collection(TransportContract::query()->where('contract_reference', $contract->contract_reference)->orderByDesc('version_no')->get())->resolve(),
            'settlements' => TransportSettlementResource::collection($contract->settlements()->latest('period_start')->limit(12)->get())->resolve(),
            'can' => [
                'update' => $user->can('transport-contracts.update'),
                'approve' => $user->can('transport-contracts.update') && $contract->created_by !== $user->id,
                'settlements' => $user->can('transport-settlements.viewAny'),
            ],
        ]);
    }

    public function edit(Request $request, TransportContract $contract, OrganizationScopeService $scope): Response
    {
        abort_unless($request->user()?->can('transport-contracts.update'), 403);
        $this->guardScope($request, $contract, $scope);

        return Inertia::render('Transport/Contracts/Edit', ['contract' => (new TransportContractResource($contract->load(['provider', 'organization'])))->resolve(), ...$this->formPayload()]);
    }

    public function update(StoreTransportContractRequest $request, TransportContract $contract, TransportContractService $contracts, OrganizationScopeService $scope): RedirectResponse
    {
        $this->guardScope($request, $contract, $scope);
        $data = $request->safe()->except(['agreement', 'contract_reference', 'provider_id']);
        if ($request->hasFile('agreement')) {
            $data['agreement_path'] = $request->file('agreement')->store('transport/contracts', 'local');
        }

        return $this->act(fn () => $contracts->updateDraft($contract, $data, $request->user(), $request), $contract, __('transport.contract_saved'));
    }

    public function approve(Request $request, TransportContract $contract, TransportContractService $contracts, OrganizationScopeService $scope): RedirectResponse
    {
        abort_unless($request->user()?->can('transport-contracts.update'), 403);
        $this->guardScope($request, $contract, $scope);

        return $this->act(fn () => $contracts->approve($contract, $request->user(), $request), $contract, __('transport.contract_approved'));
    }

    public function newVersion(Request $request, TransportContract $contract, TransportContractService $contracts, OrganizationScopeService $scope): RedirectResponse
    {
        abort_unless($request->user()?->can('transport-contracts.create'), 403);
        $this->guardScope($request, $contract, $scope);

        try {
            $next = $contracts->newVersion($contract, $request->user(), $request);
        } catch (DomainException $e) {
            return back()->with('flash', ['type' => 'error', 'message' => $e->getMessage()]);
        }

        return to_route('transport.contracts.edit', $next)->with('flash', ['type' => 'success', 'message' => __('transport.contract_version_created')]);
    }

    public function end(Request $request, TransportContract $contract, TransportContractService $contracts, OrganizationScopeService $scope): RedirectResponse
    {
        abort_unless($request->user()?->can('transport-contracts.update'), 403);
        $this->guardScope($request, $contract, $scope);
        $data = $request->validate(['effective_to' => ['required', 'date']]);

        return $this->act(fn () => $contracts->end($contract, Carbon::parse($data['effective_to']), $request->user(), $request), $contract, __('transport.contract_ended'));
    }

    public function cancel(Request $request, TransportContract $contract, TransportContractService $contracts, OrganizationScopeService $scope): RedirectResponse
    {
        abort_unless($request->user()?->can('transport-contracts.update'), 403);
        $this->guardScope($request, $contract, $scope);

        return $this->act(fn () => $contracts->cancel($contract, $request->user(), $request), $contract, __('transport.contract_cancelled'));
    }

    public function agreement(Request $request, TransportContract $contract, OrganizationScopeService $scope)
    {
        abort_unless($request->user()?->can('transport-contracts.view'), 403);
        $this->guardScope($request, $contract, $scope);
        abort_if($contract->agreement_path === null, 404);

        return response()->download(storage_path('app/private/'.$contract->agreement_path), $contract->contract_reference.'-v'.$contract->version_no.'.pdf');
    }

    /** @param  callable(): TransportContract  $action */
    private function act(callable $action, TransportContract $contract, string $message): RedirectResponse
    {
        try {
            $action();
        } catch (DomainException $e) {
            return back()->with('flash', ['type' => 'error', 'message' => $e->getMessage()]);
        }

        return to_route('transport.contracts.show', $contract)->with('flash', ['type' => 'success', 'message' => $message]);
    }

    private function guardScope(Request $request, TransportContract $contract, OrganizationScopeService $scope): void
    {
        if ($contract->organization_id !== null) {
            abort_unless($scope->canAccessOrganization($request->user(), $contract->organization_id), 403);
        }
    }

    /** @return array<string, mixed> */
    private function formPayload(): array
    {
        return [
            'today' => today()->toDateString(),
            'providers' => $this->providers(),
            'models' => array_map(fn (TransportSettlementModel $m): array => ['value' => $m->value, 'terms' => $m->requiredTerms()], TransportSettlementModel::cases()),
            'periods' => TransportSettlementPeriod::values(),
            'termKeys' => TransportSettlementModel::allTerms(),
        ];
    }

    private function providers()
    {
        return app(ProviderDirectory::class)->offering('transport');
    }
}

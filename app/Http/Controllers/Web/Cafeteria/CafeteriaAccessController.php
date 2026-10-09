<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Cafeteria;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\Cafeteria\Concerns\CafeteriaAdminContext;
use App\Models\OrganizationCafeteriaAccess;
use App\Services\Cafeteria\Network\CafeteriaAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/** Cafeteria Management → Organization Access. */
class CafeteriaAccessController extends Controller
{
    use CafeteriaAdminContext;

    public function __construct(private readonly CafeteriaAccessService $access) {}

    public function index(Request $request): Response
    {
        $rows = $this->withinOrganizationScope(OrganizationCafeteriaAccess::query(), $request->user())
            ->with([
                'organization:id,code,name_en,name_am',
                'network:id,code,name_en,name_am,provider_id',
                'network.provider:id,provider_code,name_en,name_am',
                'primaryCafeteria:id,code,name_en,name_am',
            ])
            ->withCount('locationExceptions')
            ->when($request->filled('organization_id'), fn ($q) => $q->where('organization_id', $request->string('organization_id')->toString()))
            ->when($request->filled('network_id'), fn ($q) => $q->where('cafeteria_service_network_id', $request->string('network_id')->toString()))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->toString()))
            ->orderByDesc('effective_from')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Cafeteria/Access/Index', [
            'rows' => collect($rows->items())->map(fn (OrganizationCafeteriaAccess $a): array => [
                'id' => $a->id,
                'organization' => $this->namePair($a->organization),
                'provider' => $this->namePair($a->network?->provider, 'provider_code'),
                'network' => $this->namePair($a->network),
                'primary_cafeteria' => $this->namePair($a->primaryCafeteria),
                'allow_cross_location_usage' => $a->allow_cross_location_usage,
                'exception_count' => (int) $a->location_exceptions_count,
                'effective_from' => $a->effective_from?->toDateString(),
                'effective_to' => $a->effective_to?->toDateString(),
                'status' => $a->status?->value,
            ])->all(),
            'meta' => ['currentPage' => $rows->currentPage(), 'lastPage' => $rows->lastPage(), 'total' => $rows->total(), 'perPage' => $rows->perPage()],
            'filters' => $request->only(['organization_id', 'network_id', 'status']),
            'organizations' => $this->organizationOptions($request->user()),
            'networks' => $this->networkOptions(),
            'can' => [
                'manage' => $request->user()->can('cafeteria_access.manage'),
                'approve' => $request->user()->can('cafeteria_access.approve'),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Cafeteria/Access/Form', [
            'access' => null,
            'organizations' => $this->organizationOptions($request->user()),
            'networks' => $this->networkOptions(),
            'cafeterias' => $this->cafeteriaOptions(),
            'defaults' => ['cafeteria_service_network_id' => $request->string('network_id')->toString(), 'effective_from' => today()->toDateString()],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $this->assertOrganizationInScope($request->user(), $data['organization_id']);

        $this->access->create($data, $request->user(), $request);

        return to_route('cafeteria.access.index')
            ->with('flash', ['message' => __('cafeteria-policy.access_saved'), 'type' => 'success']);
    }

    public function edit(Request $request, OrganizationCafeteriaAccess $access): Response
    {
        $this->assertOrganizationInScope($request->user(), $access->organization_id);
        $access->load('locationExceptions');

        return Inertia::render('Cafeteria/Access/Form', [
            'access' => [
                ...$access->only(['id', 'organization_id', 'cafeteria_service_network_id', 'primary_cafeteria_id', 'allow_cross_location_usage', 'notes']),
                'status' => $access->status?->value,
                'effective_from' => $access->effective_from?->toDateString(),
                'effective_to' => $access->effective_to?->toDateString(),
                'location_exceptions' => $access->locationExceptions->map(fn ($e) => [
                    'cafeteria_id' => $e->cafeteria_id,
                    'is_allowed' => $e->is_allowed,
                    'effective_from' => $e->effective_from?->toDateString(),
                    'effective_to' => $e->effective_to?->toDateString(),
                ])->all(),
            ],
            'organizations' => $this->organizationOptions($request->user()),
            'networks' => $this->networkOptions(),
            'cafeterias' => $this->cafeteriaOptions(),
            'defaults' => [],
        ]);
    }

    public function update(Request $request, OrganizationCafeteriaAccess $access): RedirectResponse
    {
        $this->assertOrganizationInScope($request->user(), $access->organization_id);
        $this->access->update($access, $this->validated($request, $access), $request->user(), $request);

        return to_route('cafeteria.access.index')
            ->with('flash', ['message' => __('cafeteria-policy.access_saved'), 'type' => 'success']);
    }

    public function approve(Request $request, OrganizationCafeteriaAccess $access): RedirectResponse
    {
        $this->assertOrganizationInScope($request->user(), $access->organization_id);
        $this->access->approve($access, $request->user(), $request);

        return back()->with('flash', ['message' => __('cafeteria-policy.access_approved'), 'type' => 'success']);
    }

    public function end(Request $request, OrganizationCafeteriaAccess $access): RedirectResponse
    {
        $this->assertOrganizationInScope($request->user(), $access->organization_id);
        $data = $request->validate(['effective_to' => ['required', 'date']]);
        $this->access->end($access, Carbon::parse($data['effective_to']), $request->user(), $request);

        return back()->with('flash', ['message' => __('cafeteria-policy.access_ended'), 'type' => 'success']);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?OrganizationCafeteriaAccess $access = null): array
    {
        return $request->validate([
            // Organization and network are fixed once access exists.
            'organization_id' => [$access ? 'prohibited' : 'required', 'uuid', 'exists:organizations,id'],
            'cafeteria_service_network_id' => [$access ? 'prohibited' : 'required', 'uuid', 'exists:cafeteria_service_networks,id'],
            'primary_cafeteria_id' => ['nullable', 'uuid', 'exists:cafeteria_providers,id'],
            'allow_cross_location_usage' => ['boolean'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'location_exceptions' => ['array', 'max:50'],
            'location_exceptions.*.cafeteria_id' => ['required', 'uuid', 'exists:cafeteria_providers,id'],
            'location_exceptions.*.is_allowed' => ['required', 'boolean'],
            'location_exceptions.*.effective_from' => ['nullable', 'date'],
            'location_exceptions.*.effective_to' => ['nullable', 'date'],
        ]);
    }
}

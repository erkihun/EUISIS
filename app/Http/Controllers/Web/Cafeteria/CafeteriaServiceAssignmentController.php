<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Cafeteria;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\Cafeteria\Concerns\CafeteriaAdminContext;
use App\Models\CafeteriaServiceAssignment;
use App\Services\Cafeteria\Network\CafeteriaAssignmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/** Cafeteria Management → Service Assignments. */
class CafeteriaServiceAssignmentController extends Controller
{
    use CafeteriaAdminContext;

    public function __construct(private readonly CafeteriaAssignmentService $assignments) {}

    public function index(Request $request): Response
    {
        $rows = $this->withinOrganizationScope(CafeteriaServiceAssignment::query(), $request->user())
            ->with([
                'organization:id,code,name_en,name_am',
                'provider:id,provider_code,name_en,name_am',
                'network:id,code,name_en,name_am',
                'cafeteria:id,code,name_en,name_am',
            ])
            ->withCount('policies')
            ->when($request->filled('organization_id'), fn ($q) => $q->where('organization_id', $request->string('organization_id')->toString()))
            ->when($request->filled('provider_id'), fn ($q) => $q->where('provider_id', $request->string('provider_id')->toString()))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->toString()))
            ->orderByDesc('effective_from')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Cafeteria/Assignments/Index', [
            'rows' => collect($rows->items())->map(fn (CafeteriaServiceAssignment $a): array => $this->present($a))->all(),
            'meta' => ['currentPage' => $rows->currentPage(), 'lastPage' => $rows->lastPage(), 'total' => $rows->total(), 'perPage' => $rows->perPage()],
            'filters' => $request->only(['organization_id', 'provider_id', 'status']),
            'organizations' => $this->organizationOptions($request->user()),
            'providers' => $this->providerOptions(),
            'can' => [
                'create' => $request->user()->can('cafeteria_assignments.create'),
                'update' => $request->user()->can('cafeteria_assignments.update'),
                'approve' => $request->user()->can('cafeteria_assignments.approve'),
                'end' => $request->user()->can('cafeteria_assignments.end'),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Cafeteria/Assignments/Form', [
            'assignment' => null,
            ...$this->options($request),
            'defaults' => ['effective_from' => today()->toDateString()],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $this->assertOrganizationInScope($request->user(), $data['organization_id']);

        $this->assignments->create($data, $request->user(), $request);

        return to_route('cafeteria.assignments.index')
            ->with('flash', ['message' => __('cafeteria-policy.assignment_saved'), 'type' => 'success']);
    }

    public function edit(Request $request, CafeteriaServiceAssignment $assignment): Response
    {
        $this->assertOrganizationInScope($request->user(), $assignment->organization_id);

        return Inertia::render('Cafeteria/Assignments/Form', [
            'assignment' => [
                ...$assignment->only(['id', 'organization_id', 'provider_id', 'cafeteria_service_network_id', 'cafeteria_id', 'notes']),
                'status' => $assignment->status?->value,
                'effective_from' => $assignment->effective_from?->toDateString(),
                'effective_to' => $assignment->effective_to?->toDateString(),
            ],
            ...$this->options($request),
            'defaults' => [],
        ]);
    }

    public function update(Request $request, CafeteriaServiceAssignment $assignment): RedirectResponse
    {
        $this->assertOrganizationInScope($request->user(), $assignment->organization_id);
        $this->assignments->update($assignment, $this->validated($request, $assignment), $request->user(), $request);

        return to_route('cafeteria.assignments.index')
            ->with('flash', ['message' => __('cafeteria-policy.assignment_saved'), 'type' => 'success']);
    }

    public function approve(Request $request, CafeteriaServiceAssignment $assignment): RedirectResponse
    {
        $this->assertOrganizationInScope($request->user(), $assignment->organization_id);
        $this->assignments->approve($assignment, $request->user(), $request);

        return back()->with('flash', ['message' => __('cafeteria-policy.assignment_approved'), 'type' => 'success']);
    }

    public function end(Request $request, CafeteriaServiceAssignment $assignment): RedirectResponse
    {
        $this->assertOrganizationInScope($request->user(), $assignment->organization_id);
        $data = $request->validate(['effective_to' => ['required', 'date']]);
        $this->assignments->end($assignment, Carbon::parse($data['effective_to']), $request->user(), $request);

        return back()->with('flash', ['message' => __('cafeteria-policy.assignment_ended'), 'type' => 'success']);
    }

    /** @return array<string, mixed> */
    private function present(CafeteriaServiceAssignment $a): array
    {
        return [
            'id' => $a->id,
            'organization' => $this->namePair($a->organization),
            'provider' => $this->namePair($a->provider, 'provider_code'),
            'network' => $this->namePair($a->network),
            'cafeteria' => $this->namePair($a->cafeteria),
            'policy_count' => (int) ($a->policies_count ?? 0),
            'effective_from' => $a->effective_from?->toDateString(),
            'effective_to' => $a->effective_to?->toDateString(),
            'status' => $a->status?->value,
        ];
    }

    /** @return array<string, mixed> */
    private function options(Request $request): array
    {
        return [
            'organizations' => $this->organizationOptions($request->user()),
            'providers' => $this->providerOptions(),
            'networks' => $this->networkOptions(),
            'cafeterias' => $this->cafeteriaOptions(),
        ];
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?CafeteriaServiceAssignment $assignment = null): array
    {
        return $request->validate([
            'organization_id' => [$assignment ? 'prohibited' : 'required', 'uuid', 'exists:organizations,id'],
            'provider_id' => ['required', 'uuid', 'exists:providers,id'],
            'cafeteria_service_network_id' => ['nullable', 'uuid', 'exists:cafeteria_service_networks,id'],
            'cafeteria_id' => ['nullable', 'uuid', 'exists:cafeteria_providers,id'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
    }
}

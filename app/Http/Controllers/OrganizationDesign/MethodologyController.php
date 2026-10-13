<?php

declare(strict_types=1);

namespace App\Http\Controllers\OrganizationDesign;

use App\Enums\OrganizationDesignStatus;
use App\Enums\RoundingRule;
use App\Http\Controllers\Controller;
use App\Http\Requests\OrganizationDesign\MethodologyRequest;
use App\Models\WorkforcePlanningMethodology;
use App\Services\OrganizationDesign\ManpowerCalculationService;
use App\Services\OrganizationDesign\WorkforcePlanningService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** City-level manpower methodologies: whitelisted strategies, schema-checked parameters, versioned. */
class MethodologyController extends Controller
{
    public function __construct(private readonly WorkforcePlanningService $planning, private readonly ManpowerCalculationService $calculator) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', WorkforcePlanningMethodology::class);
        $user = $request->user();
        $methodologies = WorkforcePlanningMethodology::query()->with(['approver:id,name', 'creator:id,name'])
            ->when($request->string('status')->toString(), fn ($q, $s) => $q->where('status', $s))
            ->when($request->string('calculation_type')->toString(), fn ($q, $t) => $q->where('calculation_type', $t))
            ->when($request->string('search')->toString(), fn ($q, $search) => $q->where(fn ($n) => $n->where('code', ci_like_operator(), "%{$search}%")->orWhere('name_en', ci_like_operator(), "%{$search}%")->orWhere('name_am', ci_like_operator(), "%{$search}%")))
            ->orderBy('code')->orderByDesc('version_no')->paginate(25)->withQueryString()
            ->through(fn (WorkforcePlanningMethodology $m) => $this->row($m, $user));

        return Inertia::render('OrganizationDesign/Methodologies/Index', [
            'methodologies' => $methodologies,
            'filters' => $request->only(['status', 'calculation_type', 'search']),
            'strategies' => $this->strategyCatalog(),
            'roundingRules' => RoundingRule::values(),
            'statuses' => [OrganizationDesignStatus::Draft->value, OrganizationDesignStatus::Published->value, OrganizationDesignStatus::Superseded->value, OrganizationDesignStatus::Cancelled->value],
            'can' => ['manage' => $user->can('create', WorkforcePlanningMethodology::class), 'approve' => $user->can('methodologies.approve')],
        ]);
    }

    public function store(MethodologyRequest $request): RedirectResponse
    {
        $this->planning->createMethodology($request->user(), $request->validated());

        return back()->with('flash', ['message' => __('organization-design.methodology_saved'), 'type' => 'success']);
    }

    public function update(MethodologyRequest $request, WorkforcePlanningMethodology $methodology): RedirectResponse
    {
        $this->planning->updateMethodology($request->user(), $methodology, $request->validated());

        return back()->with('flash', ['message' => __('organization-design.methodology_saved'), 'type' => 'success']);
    }

    public function storeVersion(MethodologyRequest $request, WorkforcePlanningMethodology $methodology): RedirectResponse
    {
        $this->planning->newMethodologyVersion($request->user(), $methodology, $request->validated());

        return back()->with('flash', ['message' => __('organization-design.methodology_version_created'), 'type' => 'success']);
    }

    public function publish(Request $request, WorkforcePlanningMethodology $methodology): RedirectResponse
    {
        $this->authorize('publish', $methodology);
        $this->planning->publishMethodology($request->user(), $methodology);

        return back()->with('flash', ['message' => __('organization-design.methodology_published'), 'type' => 'success']);
    }

    /** @return array<string, mixed> */
    private function row(WorkforcePlanningMethodology $m, $user): array
    {
        return [
            'id' => $m->id, 'code' => $m->code, 'version_no' => $m->version_no, 'lineage_key' => $m->lineage_key, 'name_en' => $m->name_en, 'name_am' => $m->name_am,
            'description_en' => $m->description_en, 'description_am' => $m->description_am, 'calculation_type' => $m->calculation_type, 'parameters' => $m->parameters ?? [],
            'rounding_rule' => $m->rounding_rule, 'status' => $m->status->value, 'effective_from' => $m->effective_from?->toDateString(), 'effective_to' => $m->effective_to?->toDateString(),
            'created_by' => $m->creator?->name, 'approved_by' => $m->approver?->name,
            'missing_parameters' => array_keys($this->calculator->parameterErrors($m->calculation_type, $m->parameters ?? [], complete: true)),
            'can' => [
                'update' => $m->status->isEditable() && $user->can('update', $m),
                'publish' => $m->status->isEditable() && $user->can('publish', $m) && (int) $m->created_by !== (int) $user->getKey(),
                'version' => $m->status === OrganizationDesignStatus::Published && $user->can('version', $m),
            ],
        ];
    }

    /** @return list<array{type: string, parameters: array<string, array<string, mixed>>, inputs: array<string, array<string, mixed>>}> */
    private function strategyCatalog(): array
    {
        return array_map(fn (string $type) => [
            'type' => $type,
            'parameters' => $this->calculator->strategy($type)->parameterSchema(),
            'inputs' => $this->calculator->strategy($type)->inputSchema(),
        ], $this->calculator->strategies());
    }
}

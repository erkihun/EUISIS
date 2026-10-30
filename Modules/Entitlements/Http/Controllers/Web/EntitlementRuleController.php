<?php

declare(strict_types=1);

namespace Modules\Entitlements\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Entitlements\Application\Actions\EntitlementRules\ArchiveEntitlementRuleAction;
use Modules\Entitlements\Application\Actions\EntitlementRules\CreateEntitlementRuleAction;
use Modules\Entitlements\Application\Actions\EntitlementRules\RestoreEntitlementRuleAction;
use Modules\Entitlements\Application\Actions\EntitlementRules\UpdateEntitlementRuleAction;
use Modules\Entitlements\Domain\Models\EntitlementRule;
use Modules\Entitlements\Domain\Models\ServiceType;
use Modules\Entitlements\Http\Requests\StoreEntitlementRuleRequest;
use Modules\Entitlements\Http\Requests\UpdateEntitlementRuleRequest;
use Modules\Entitlements\Http\Resources\EntitlementRuleResource;

class EntitlementRuleController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', EntitlementRule::class);

        $rules = EntitlementRule::query()
            ->with('serviceType:id,name_en,name_am')
            ->withCount('entitlements')
            ->when($request->string('search')->toString() !== '', function ($query) use ($request): void {
                $search = $request->string('search')->toString();
                $query->where('name', ci_like_operator(), "%{$search}%");
            })
            ->when($request->string('service_type_id')->toString() !== '', fn ($query) => $query->where('service_type_id', $request->string('service_type_id')->toString()))
            ->when($request->filled('is_active'), fn ($query) => $query->where('is_active', $request->boolean('is_active')))
            ->orderBy('name')
            ->get();

        return Inertia::render('EntitlementRules/Index', [
            'rules' => EntitlementRuleResource::collection($rules)->resolve(),
            'filters' => $request->only(['search', 'service_type_id', 'is_active']),
            'serviceTypes' => ServiceType::query()->orderBy('name_en')->get(['id', 'name_en', 'name_am']),
            'can' => [
                'create' => $request->user()?->can('create', EntitlementRule::class) ?? false,
            ],
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', EntitlementRule::class);

        return Inertia::render('EntitlementRules/Create', [
            'serviceTypes' => ServiceType::query()->orderBy('name_en')->get(['id', 'name_en', 'name_am']),
        ]);
    }

    public function store(StoreEntitlementRuleRequest $request, CreateEntitlementRuleAction $action): RedirectResponse
    {
        $rule = $action->execute($request->validated(), $request->user());

        return to_route('entitlement-rules.show', $rule)
            ->with('flash', ['message' => __('entitlement-rules.created'), 'type' => 'success']);
    }

    public function show(EntitlementRule $entitlementRule): Response
    {
        $this->authorize('view', $entitlementRule);

        $entitlementRule->load('serviceType:id,name_en,name_am')->loadCount('entitlements');

        return Inertia::render('EntitlementRules/Show', [
            'rule' => (new EntitlementRuleResource($entitlementRule))->resolve(),
        ]);
    }

    public function edit(EntitlementRule $entitlementRule): Response
    {
        $this->authorize('update', $entitlementRule);

        $entitlementRule->load('serviceType:id,name_en,name_am');

        return Inertia::render('EntitlementRules/Edit', [
            'rule' => (new EntitlementRuleResource($entitlementRule))->resolve(),
            'serviceTypes' => ServiceType::query()->orderBy('name_en')->get(['id', 'name_en', 'name_am']),
        ]);
    }

    public function update(UpdateEntitlementRuleRequest $request, EntitlementRule $entitlementRule, UpdateEntitlementRuleAction $action): RedirectResponse
    {
        $action->execute($entitlementRule, $request->validated(), $request->user());

        return to_route('entitlement-rules.show', $entitlementRule)
            ->with('flash', ['message' => __('entitlement-rules.updated'), 'type' => 'success']);
    }

    public function archive(Request $request, EntitlementRule $entitlementRule, ArchiveEntitlementRuleAction $action): RedirectResponse
    {
        $this->authorize('archive', $entitlementRule);

        $action->execute($entitlementRule, $request->user(), $request->string('reason')->toString() ?: null, $request);

        return to_route('entitlement-rules.index')->with('flash', ['message' => __('recycle-bin.archived_successfully'), 'type' => 'success']);
    }

    public function restore(Request $request, string $entitlementRule, RestoreEntitlementRuleAction $action): RedirectResponse
    {
        $entitlementRule = EntitlementRule::query()->withTrashed()->findOrFail($entitlementRule);

        $this->authorize('restore', $entitlementRule);

        $action->execute($entitlementRule, $request->user(), $request);

        return back()->with('flash', ['message' => __('recycle-bin.restored_successfully'), 'type' => 'success']);
    }
}

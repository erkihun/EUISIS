<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Cafeteria\ArchiveCafeteriaSubsidyRuleAction;
use App\Actions\Cafeteria\CreateCafeteriaSubsidyRuleAction;
use App\Actions\Cafeteria\UpdateCafeteriaSubsidyRuleAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCafeteriaSubsidyRuleRequest;
use App\Http\Requests\UpdateCafeteriaSubsidyRuleRequest;
use App\Http\Resources\CafeteriaSubsidyRuleResource;
use App\Models\CafeteriaSubsidyRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CafeteriaSubsidyRuleController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', CafeteriaSubsidyRule::class);

        $isActiveFilter = $request->string('is_active')->toString();

        $query = CafeteriaSubsidyRule::query()
            ->when($isActiveFilter === '0', fn ($q) => $q->onlyTrashed())
            ->when($isActiveFilter === '', fn ($q) => $q->withoutTrashed())
            ->when($request->string('search')->toString() !== '', function ($q) use ($request): void {
                $search = $request->string('search')->toString();
                $q->where(function ($nested) use ($search): void {
                    $nested->where('code', ci_like_operator(), "%{$search}%")
                        ->orWhere('name_en', ci_like_operator(), "%{$search}%")
                        ->orWhere('name_am', ci_like_operator(), "%{$search}%");
                });
            })
            ->orderByDesc('effective_from');

        $rules = $query->paginate(30)->withQueryString();

        return Inertia::render('Cafeteria/SubsidyRules/Index', [
            'rules' => CafeteriaSubsidyRuleResource::collection($rules)->resolve(),
            'meta' => [
                'current_page' => $rules->currentPage(),
                'last_page' => $rules->lastPage(),
                'total' => $rules->total(),
                'per_page' => $rules->perPage(),
            ],
            'filters' => $request->only(['search', 'is_active']),
            'can' => [
                'create' => $request->user()?->can('create', CafeteriaSubsidyRule::class) ?? false,
            ],
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', CafeteriaSubsidyRule::class);

        return Inertia::render('Cafeteria/SubsidyRules/Create');
    }

    public function store(StoreCafeteriaSubsidyRuleRequest $request, CreateCafeteriaSubsidyRuleAction $action): RedirectResponse
    {
        $rule = $action->execute($request->validated(), $request->user(), $request);

        return to_route('cafeteria.subsidy-rules.index')
            ->with('flash', ['message' => __('cafeteria.subsidyRuleCreated'), 'type' => 'success']);
    }

    public function edit(CafeteriaSubsidyRule $cafeteriaSubsidyRule): Response
    {
        $this->authorize('update', $cafeteriaSubsidyRule);

        return Inertia::render('Cafeteria/SubsidyRules/Edit', [
            'rule' => (new CafeteriaSubsidyRuleResource($cafeteriaSubsidyRule))->resolve(),
        ]);
    }

    public function update(UpdateCafeteriaSubsidyRuleRequest $request, CafeteriaSubsidyRule $cafeteriaSubsidyRule, UpdateCafeteriaSubsidyRuleAction $action): RedirectResponse
    {
        $action->execute($cafeteriaSubsidyRule, $request->validated(), $request->user(), $request);

        return to_route('cafeteria.subsidy-rules.index')
            ->with('flash', ['message' => __('cafeteria.subsidyRuleUpdated'), 'type' => 'success']);
    }

    public function archive(Request $request, CafeteriaSubsidyRule $cafeteriaSubsidyRule, ArchiveCafeteriaSubsidyRuleAction $action): RedirectResponse
    {
        $this->authorize('archive', $cafeteriaSubsidyRule);

        $action->execute($cafeteriaSubsidyRule, $request->user(), $request->string('reason')->toString() ?: null, $request);

        return to_route('cafeteria.subsidy-rules.index')
            ->with('flash', ['message' => __('cafeteria.subsidyRuleArchived'), 'type' => 'success']);
    }
}

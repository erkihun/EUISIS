<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Enums\PublicFeedbackHandlerType;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePublicFeedbackRoutingRuleRequest;
use App\Models\Organization;
use App\Models\PublicFeedbackCategory;
use App\Models\PublicFeedbackRoutingRule;
use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use App\Services\ServiceFeedback\PublicFeedbackAssignmentService;
use App\Services\ServiceFeedback\PublicFeedbackRoutingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Routing rules for public suggestions and complaints (brief §45–47). A
 * scoped administrator manages the rules of their own institutions; city-wide
 * rules take a city-level account. A rule is created and edited on its own
 * page, like every other record.
 */
class PublicFeedbackRoutingRuleController extends Controller
{
    public function __construct(
        private readonly PublicFeedbackRoutingService $routing,
        private readonly PublicFeedbackAssignmentService $assignments,
        private readonly OrganizationScopeService $scope,
    ) {}

    public function index(Request $request): Response
    {
        $user = $this->user();
        abort_unless($user->hasAnyPermission([PublicFeedbackRoutingService::PERMISSION]), 403);

        $names = [];

        // The same filter bar as every other list: status and organization, kept in the query string.
        $status = $request->string('status')->toString();
        $filters = [
            'status' => in_array($status, ['active', 'inactive'], true) ? $status : '',
            'organization_id' => $request->string('organization_id')->toString(),
        ];

        return Inertia::render('ServiceFeedback/RoutingRules/Index', [
            'filters' => $filters,
            'rules' => $this->routing->queryFor($user)
                ->when($filters['status'] !== '', fn ($query) => $query->where('is_active', $filters['status'] === 'active'))
                ->when($filters['organization_id'] !== '', fn ($query) => $query->where('organization_id', $filters['organization_id']))
                ->orderByRaw('CASE WHEN organization_id IS NULL THEN 0 ELSE 1 END')
                ->orderBy('priority')->orderBy('created_at')
                ->get()
                ->map(fn (PublicFeedbackRoutingRule $rule): array => $this->present($rule, $user, $names))->values()->all(),
            ...$this->formOptions($user),
        ]);
    }

    public function create(): Response
    {
        $user = $this->user();
        abort_unless($user->hasAnyPermission([PublicFeedbackRoutingService::PERMISSION]), 403);

        $options = $this->formOptions($user);
        abort_unless($options['canManageCityWide'] || $options['organizations'] !== [], 403);

        return Inertia::render('ServiceFeedback/RoutingRules/Create', $options);
    }

    public function edit(PublicFeedbackRoutingRule $rule): Response
    {
        $user = $this->user();
        abort_unless($this->routing->canManage($user, $rule->organization_id), 403);

        $names = [];

        return Inertia::render('ServiceFeedback/RoutingRules/Edit', [
            'rule' => $this->present($rule, $user, $names),
            ...$this->formOptions($user),
        ]);
    }

    /** Handler choices for one organization (or city-wide when none), for the rule form and the assignment dialog. */
    public function handlers(Request $request): JsonResponse
    {
        $user = $this->user();
        abort_unless($user->hasAnyPermission([PublicFeedbackRoutingService::PERMISSION, 'service_feedback.assign', 'service_feedback.reassign']), 403);

        $organizationId = $request->filled('organization_id') ? (string) $request->query('organization_id') : null;
        abort_unless($organizationId === null
            ? $this->scope->isUnrestricted($user)
            : $this->scope->isUnrestricted($user) || $this->scope->canAccessOrganization($user, $organizationId), 403);

        $options = $this->assignments->options($organizationId);

        if ($organizationId === null) {
            // A city-wide rule may only name users who work across the city.
            $options['users'] = array_values(array_filter(
                $options['users'],
                fn (array $row): bool => ($candidate = User::query()->find($row['id'])) !== null && $this->scope->isUnrestricted($candidate),
            ));
        }

        return response()->json($options + ['handler_types' => PublicFeedbackHandlerType::values()]);
    }

    public function store(StorePublicFeedbackRoutingRuleRequest $request): RedirectResponse
    {
        $data = $request->validated();
        abort_unless($this->routing->canManage($this->user(), $data['organization_id'] ?? null), 403);

        $this->routing->store($this->user(), $data, $request);

        return redirect()->route('service-feedback.routing-rules.index')->with('success', __('service-feedback.rule_saved'));
    }

    public function update(StorePublicFeedbackRoutingRuleRequest $request, PublicFeedbackRoutingRule $rule): RedirectResponse
    {
        $data = $request->validated();
        $user = $this->user();

        // Both the current scope and the requested one must be the user's.
        abort_unless($this->routing->canManage($user, $rule->organization_id), 403);
        abort_unless($this->routing->canManage($user, $data['organization_id'] ?? null), 403);

        $this->routing->update($user, $rule, $data, $request);

        return redirect()->route('service-feedback.routing-rules.index')->with('success', __('service-feedback.rule_saved'));
    }

    public function destroy(Request $request, PublicFeedbackRoutingRule $rule): RedirectResponse
    {
        abort_unless($this->routing->canManage($this->user(), $rule->organization_id), 403);

        $outcome = $this->routing->destroy($this->user(), $rule, $request);

        return redirect()->route('service-feedback.routing-rules.index')
            ->with('success', __($outcome === 'retired' ? 'service-feedback.rule_retired' : 'service-feedback.rule_deleted'));
    }

    /**
     * @param  array<string, array{en: ?string, am: ?string}>  $names  handler names already resolved on this request
     * @return array<string, mixed>
     */
    private function present(PublicFeedbackRoutingRule $rule, User $user, array &$names): array
    {
        $key = $rule->handler_type->value.':'.$rule->handler_id;
        $names[$key] ??= $rule->handler_type->names($rule->handler_id);

        return [
            'id' => $rule->id,
            'organization_id' => $rule->organization_id,
            'organization' => $rule->organization === null ? null : ['en' => $rule->organization->name_en, 'am' => $rule->organization->name_am],
            'target_type' => $rule->target_type,
            'submission_type' => $rule->submission_type,
            'tip_type' => $rule->tip_type?->value,
            'category_id' => $rule->category_id,
            'category' => $rule->category === null ? null : ['en' => $rule->category->name_en, 'am' => $rule->category->name_am],
            'handler_type' => $rule->handler_type->value,
            'handler_id' => $rule->handler_id,
            'handler_name' => $names[$key]['en'] ?? null,
            'handler_name_am' => $names[$key]['am'] ?? null,
            'priority' => $rule->priority,
            'effective_from' => $rule->effective_from?->toDateString(),
            'effective_to' => $rule->effective_to?->toDateString(),
            'is_active' => $rule->is_active,
            'referenced' => $rule->isReferenced(),
            'can_manage' => $this->routing->canManage($user, $rule->organization_id),
        ];
    }

    /**
     * The scopes and categories a user may build a rule from.
     *
     * @return array{organizations: list<array<string, mixed>>, categories: list<array<string, mixed>>, canManageCityWide: bool}
     */
    private function formOptions(User $user): array
    {
        $scoped = $this->scope->isScopedOrganizationalAdmin($user);
        $organizationIds = $scoped ? $this->scope->allowedOrganizationIds($user) : null;
        $organizations = $scoped
            ? Organization::query()->whereIn('id', $organizationIds)
            : Organization::query()->where('status', 'active');

        return [
            'organizations' => $organizations->orderBy('name_en')->get(['id', 'code', 'name_en', 'name_am'])->toArray(),
            'categories' => PublicFeedbackCategory::query()->active()
                ->when($organizationIds !== null, fn ($q) => $q->where(fn ($q) => $q->whereNull('organization_id')->orWhereIn('organization_id', $organizationIds)))
                ->orderBy('sort_order')->orderBy('name_en')
                ->get(['id', 'organization_id', 'name_en', 'name_am'])
                ->map(fn (PublicFeedbackCategory $category): array => [
                    'id' => $category->id, 'organization_id' => $category->organization_id, 'name_en' => $category->name_en, 'name_am' => $category->name_am,
                ])->all(),
            'canManageCityWide' => $this->routing->canManage($user, null),
        ];
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Grievances;

use App\Enums\AuditEventType;
use App\Enums\Grievance\GrievanceHandlerType;
use App\Enums\Grievance\GrievanceMovementType;
use App\Enums\Grievance\GrievanceSlaDayType;
use App\Enums\Grievance\GrievanceSlaPurpose;
use App\Enums\Grievance\GrievanceSlaStartPoint;
use App\Http\Controllers\Controller;
use App\Models\GrievanceCategory;
use App\Models\GrievanceCommittee;
use App\Models\GrievanceExternalAuthority;
use App\Models\GrievanceRoute;
use App\Models\GrievanceSlaProfile;
use App\Models\Organization;
use App\Services\Grievances\GrievanceAudit;
use App\Services\Grievances\GrievanceHandlerRegistry;
use App\Services\Grievances\GrievancePresenter;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Routing graph and SLA policies (docs/grievance-management.md §5–6). A new
 * or changed route is inactive for new cases until a DIFFERENT user approves
 * it; existing stages keep the route they used. SLA edits never touch
 * existing stages (they hold a snapshot).
 */
class GrievanceRoutingController extends Controller
{
    public function __construct(
        private readonly GrievanceHandlerRegistry $handlers,
        private readonly GrievancePresenter $presenter,
        private readonly GrievanceAudit $audit,
        private readonly OrganizationScopeService $scope,
    ) {}

    // ── Routes ───────────────────────────────────────────────────────────────

    public function routes(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->can('grievance_routes.view'), 403);

        $query = GrievanceRoute::query()->with(['category:id,name_en,name_am', 'slaProfile:id,name_en,name_am', 'creator:id,name', 'approver:id,name']);
        foreach (['movement_type', 'source_handler_type', 'target_handler_type'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, (string) $request->string($filter));
            }
        }
        if ($request->filled('state')) {
            match ((string) $request->string('state')) {
                'pending' => $query->whereNull('approved_at')->where('is_active', true),
                'active' => $query->whereNotNull('approved_at')->where('is_active', true),
                'inactive' => $query->where('is_active', false),
                default => null,
            };
        }

        $page = $query->orderBy('movement_type')->orderBy('priority')->paginate(30)->withQueryString();
        $page->through(fn (GrievanceRoute $r) => [
            'id' => $r->getKey(),
            'source' => $this->presenter->handler($r->source_handler_type->value, $r->source_handler_id),
            'include_descendants' => $r->include_descendants,
            'target' => $this->presenter->handler($r->target_handler_type->value, $r->target_handler_id),
            'movement_type' => $r->movement_type->value,
            'category' => $r->category?->only(['id', 'name_en', 'name_am']),
            'sla_profile' => $r->slaProfile?->only(['id', 'name_en', 'name_am']),
            'priority' => $r->priority,
            'effective_from' => $r->effective_from?->toDateString(),
            'effective_to' => $r->effective_to?->toDateString(),
            'is_active' => $r->is_active,
            'approved_at' => $r->approved_at?->toIso8601String(),
            'approved_by' => $r->approver?->name,
            'created_by' => $r->creator?->name,
            'created_by_id' => $r->created_by,
            'notes' => $r->notes,
            'cross_organization' => $this->isCrossOrganization($r),
        ]);

        return Inertia::render('Grievances/Routing/Index', [
            'routes' => $page,
            'filters' => $request->only(['movement_type', 'source_handler_type', 'target_handler_type', 'state']),
            'options' => $this->handlerOptions($request),
            'can' => ['manage' => $user->can('grievance_routes.manage'), 'approve' => $user->can('grievance_routes.approve')],
        ]);
    }

    public function storeRoute(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('grievance_routes.manage'), 403);
        $data = $this->validateRoute($request);
        $route = GrievanceRoute::query()->create([...$data, 'is_active' => true, 'created_by' => $request->user()->getKey(), 'approved_at' => null]);
        $this->audit->record(AuditEventType::GrievanceRouteSaved, $request->user(), $route, collect($data)->except('notes')->all());

        return back()->with('flash', ['message' => __('grievances.flash.route_saved'), 'type' => 'success']);
    }

    /** Editing a route re-requires approval; its history in stages is untouched. */
    public function updateRoute(Request $request, GrievanceRoute $grievanceRoute): RedirectResponse
    {
        $route = $grievanceRoute;
        abort_unless($request->user()->can('grievance_routes.manage'), 403);
        if ($request->boolean('deactivate')) {
            $route->forceFill(['is_active' => false, 'effective_to' => $route->effective_to ?? now()->toDateString()])->save();
            $this->audit->record(AuditEventType::GrievanceRouteSaved, $request->user(), $route, ['is_active' => false]);

            return back()->with('flash', ['message' => __('grievances.flash.saved'), 'type' => 'success']);
        }

        $data = $this->validateRoute($request);
        $old = $route->only(array_keys($data));
        $route->forceFill([...$data, 'approved_at' => null, 'approved_by' => null, 'created_by' => $request->user()->getKey()])->save();
        $this->audit->record(AuditEventType::GrievanceRouteSaved, $request->user(), $route, collect($data)->except('notes')->all(), collect($old)->map(fn ($v) => $v instanceof \BackedEnum ? $v->value : $v)->except('notes')->all());

        return back()->with('flash', ['message' => __('grievances.flash.route_saved'), 'type' => 'success']);
    }

    public function approveRoute(Request $request, GrievanceRoute $grievanceRoute): RedirectResponse
    {
        $route = $grievanceRoute;
        $user = $request->user();
        abort_unless($user->can('grievance_routes.approve'), 403);
        if ((int) $route->created_by === (int) $user->getKey() && ! $user->isSuperAdmin()) {
            throw ValidationException::withMessages(['route' => __('grievances.errors.separation_of_duties')]);
        }
        $route->forceFill(['approved_at' => now(), 'approved_by' => $user->getKey()])->save();
        $this->audit->record(AuditEventType::GrievanceRouteApproved, $user, $route, ['route_id' => $route->getKey()]);

        return back()->with('flash', ['message' => __('grievances.flash.route_approved'), 'type' => 'success']);
    }

    // ── SLA profiles ─────────────────────────────────────────────────────────

    public function slaProfiles(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->can('grievance_sla.view'), 403);

        return Inertia::render('Grievances/Sla/Index', [
            'profiles' => GrievanceSlaProfile::query()->with(['organization:id,name_en,name_am', 'category:id,name_en,name_am'])
                ->orderByDesc('is_active')->orderBy('purpose')->orderBy('priority')->get()
                ->map(fn (GrievanceSlaProfile $p) => [
                    ...$p->only(['id', 'name_en', 'name_am', 'handler_id', 'resolution_days', 'priority', 'is_active']),
                    'purpose' => $p->purpose->value,
                    'handler_type' => $p->handler_type?->value,
                    'handler' => $p->handler_type && $p->handler_id ? $this->presenter->handler($p->handler_type->value, $p->handler_id) : null,
                    'day_type' => $p->day_type->value,
                    'start_point' => $p->start_point->value,
                    'warning_thresholds' => $p->warning_thresholds,
                    'auto_escalate' => $p->auto_escalate,
                    'organization' => $p->organization?->only(['id', 'name_en', 'name_am']),
                    'category' => $p->category?->only(['id', 'name_en', 'name_am']),
                    'effective_from' => $p->effective_from?->toDateString(),
                    'effective_to' => $p->effective_to?->toDateString(),
                ])->values(),
            'options' => [
                ...$this->handlerOptions($request),
                'purposes' => GrievanceSlaPurpose::values(),
                'day_types' => GrievanceSlaDayType::values(),
                'start_points' => GrievanceSlaStartPoint::values(),
            ],
            'can' => ['manage' => $user->can('grievance_sla.manage')],
        ]);
    }

    public function storeSlaProfile(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('grievance_sla.manage'), 403);
        $profile = GrievanceSlaProfile::query()->create([...$this->validateSla($request), 'created_by' => $request->user()->getKey()]);
        $this->audit->record(AuditEventType::GrievanceConfigurationChanged, $request->user(), $profile, ['sla_profile' => $profile->getKey(), 'days' => $profile->resolution_days]);

        return back()->with('flash', ['message' => __('grievances.flash.saved'), 'type' => 'success']);
    }

    /**
     * Profiles are versioned by effective dates: an edit ends the old profile
     * today and creates a new one, so reports can see which policy applied.
     */
    public function updateSlaProfile(Request $request, GrievanceSlaProfile $profile): RedirectResponse
    {
        abort_unless($request->user()->can('grievance_sla.manage'), 403);
        if ($request->boolean('deactivate')) {
            $profile->forceFill(['is_active' => false, 'effective_to' => $profile->effective_to ?? now()->toDateString()])->save();
        } else {
            $data = $this->validateSla($request);
            $profile->forceFill(['effective_to' => now()->subDay()->toDateString(), 'is_active' => false])->save();
            GrievanceSlaProfile::query()->create([...$data, 'effective_from' => now()->toDateString(), 'created_by' => $request->user()->getKey()]);
        }
        $this->audit->record(AuditEventType::GrievanceConfigurationChanged, $request->user(), $profile, ['sla_profile' => $profile->getKey(), 'deactivated' => $request->boolean('deactivate')]);

        return back()->with('flash', ['message' => __('grievances.flash.saved'), 'type' => 'success']);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function validateRoute(Request $request): array
    {
        $data = $request->validate([
            'source_handler_type' => ['required', Rule::in(GrievanceHandlerType::values())],
            'source_handler_id' => ['required', 'uuid'],
            'include_descendants' => ['nullable', 'boolean'],
            'target_handler_type' => ['required', Rule::in([GrievanceHandlerType::Committee->value, GrievanceHandlerType::OrganizationUnit->value, GrievanceHandlerType::ExternalAuthority->value])],
            'target_handler_id' => ['required', 'uuid', 'different:source_handler_id'],
            'movement_type' => ['required', Rule::in(GrievanceMovementType::values())],
            'category_id' => ['nullable', 'uuid', 'exists:grievance_categories,id'],
            'sla_profile_id' => ['nullable', 'uuid', 'exists:grievance_sla_profiles,id'],
            'priority' => ['required', 'integer', 'min:1', 'max:1000'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $sourceType = GrievanceHandlerType::from($data['source_handler_type']);
        if ($this->handlers->find($sourceType, $data['source_handler_id']) === null
            || $this->handlers->find(GrievanceHandlerType::from($data['target_handler_type']), $data['target_handler_id']) === null) {
            throw ValidationException::withMessages(['target_handler_id' => __('grievances.errors.handler_missing')]);
        }
        if (($sourceType === GrievanceHandlerType::Organization) !== ($data['movement_type'] === GrievanceMovementType::InitialAssignment->value)) {
            throw ValidationException::withMessages(['movement_type' => __('grievances.errors.initial_route_source')]);
        }
        $data['include_descendants'] = (bool) ($data['include_descendants'] ?? false) && $sourceType === GrievanceHandlerType::Organization;

        return $data;
    }

    /** @return array<string, mixed> */
    private function validateSla(Request $request): array
    {
        $data = $request->validate([
            'name_en' => ['required', 'string', 'max:255'],
            'name_am' => ['nullable', 'string', 'max:255'],
            'purpose' => ['required', Rule::in(GrievanceSlaPurpose::values())],
            'organization_id' => ['nullable', 'uuid', 'exists:organizations,id'],
            'handler_type' => ['nullable', Rule::in([GrievanceHandlerType::Committee->value, GrievanceHandlerType::OrganizationUnit->value, GrievanceHandlerType::ExternalAuthority->value])],
            'handler_id' => ['nullable', 'uuid'],
            'category_id' => ['nullable', 'uuid', 'exists:grievance_categories,id'],
            'resolution_days' => ['required', 'integer', 'min:1', 'max:365'],
            'day_type' => ['required', Rule::in(GrievanceSlaDayType::values())],
            'start_point' => ['required', Rule::in(GrievanceSlaStartPoint::values())],
            'warning_percent' => ['nullable', 'array'],
            'warning_percent.*' => ['integer', 'min:1', 'max:99'],
            'warning_days_remaining' => ['nullable', 'array'],
            'warning_days_remaining.*' => ['integer', 'min:1', 'max:60'],
            'warning_due_today' => ['nullable', 'boolean'],
            'auto_escalate' => ['required', 'boolean'],
            'priority' => ['required', 'integer', 'min:1', 'max:1000'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
        ]);
        $data['warning_thresholds'] = [
            'percent' => array_values(array_map('intval', $data['warning_percent'] ?? [])),
            'days_remaining' => array_values(array_map('intval', $data['warning_days_remaining'] ?? [])),
            'due_today' => (bool) ($data['warning_due_today'] ?? false),
        ];
        unset($data['warning_percent'], $data['warning_days_remaining'], $data['warning_due_today']);
        $data['is_active'] = true;

        return $data;
    }

    /** @return array<string, mixed> */
    private function handlerOptions(Request $request): array
    {
        $user = $request->user();

        return [
            'movement_types' => GrievanceMovementType::values(),
            'handler_types' => GrievanceHandlerType::values(),
            'organizations' => Organization::query()->where('status', 'active')->orderBy('name_en')->limit(2000)->get(['id', 'name_en', 'name_am']),
            'committees' => GrievanceCommittee::query()->whereIn('committee_type', GrievanceHandlerRegistry::grievanceCommitteeTypes())
                ->where('status', '!=', 'inactive')->with('organization:id,name_en,name_am')->orderBy('name_en')->get(['id', 'name_en', 'name_am', 'organization_id', 'status']),
            'external_authorities' => GrievanceExternalAuthority::query()->where('is_active', true)->orderBy('name_en')->get(['id', 'name_en', 'name_am']),
            'categories' => GrievanceCategory::query()->where('is_active', true)->orderBy('name_en')->get(['id', 'name_en', 'name_am']),
            'sla_profiles' => GrievanceSlaProfile::query()->where('is_active', true)->where('purpose', 'resolution')->orderBy('name_en')->get(['id', 'name_en', 'name_am']),
            // Units are loaded per organization through the lookup endpoint.
        ];
    }

    private function isCrossOrganization(GrievanceRoute $route): bool
    {
        $source = $this->handlers->organizationIdOf($route->source_handler_type, $route->source_handler_id);
        $target = $this->handlers->organizationIdOf($route->target_handler_type, $route->target_handler_id);

        return $source !== null && $target !== null && $source !== $target;
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Cafeteria;

use App\Enums\CafeteriaExtraScanPolicy;
use App\Enums\CafeteriaGrantStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\Cafeteria\Concerns\CafeteriaAdminContext;
use App\Models\CafeteriaServiceAssignment;
use App\Models\CafeteriaServicePolicy;
use App\Services\Cafeteria\CafeteriaSettingsService;
use App\Services\Cafeteria\Policy\CafeteriaPolicyWorkflowService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Cafeteria Management → Service Policies. */
class CafeteriaServicePolicyController extends Controller
{
    use CafeteriaAdminContext;

    public function __construct(private readonly CafeteriaPolicyWorkflowService $workflow) {}

    public function index(Request $request): Response
    {
        $rows = $this->withinOrganizationScope(CafeteriaServicePolicy::query(), $request->user())
            ->with([
                'organization:id,code,name_en,name_am',
                'provider:id,provider_code,name_en,name_am',
                'network:id,code,name_en,name_am',
                'cafeteria:id,code,name_en,name_am',
            ])
            ->when($request->filled('organization_id'), fn ($q) => $q->where('organization_id', $request->string('organization_id')->toString()))
            ->when($request->filled('provider_id'), fn ($q) => $q->where('provider_id', $request->string('provider_id')->toString()))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->toString()))
            ->orderBy('organization_id')
            ->orderBy('policy_group_id')
            ->orderByDesc('version_no')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Cafeteria/Policies/Index', [
            'rows' => collect($rows->items())->map(fn (CafeteriaServicePolicy $p): array => $this->summary($p))->all(),
            'meta' => ['currentPage' => $rows->currentPage(), 'lastPage' => $rows->lastPage(), 'total' => $rows->total(), 'perPage' => $rows->perPage()],
            'filters' => $request->only(['organization_id', 'provider_id', 'status']),
            'organizations' => $this->organizationOptions($request->user()),
            'providers' => $this->providerOptions(),
            'can' => ['create' => $request->user()->can('cafeteria_policies.create')],
        ]);
    }

    public function create(Request $request, CafeteriaSettingsService $settings): Response
    {
        return Inertia::render('Cafeteria/Policies/Form', [
            'policy' => null,
            'assignments' => $this->assignmentOptions($request),
            'cafeterias' => $this->cafeteriaOptions(),
            'defaults' => [
                'cafeteria_service_assignment_id' => $request->string('assignment_id')->toString(),
                ...$this->systemDefaults($settings),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, creating: true);
        $assignment = CafeteriaServiceAssignment::query()->findOrFail($data['cafeteria_service_assignment_id']);
        $this->assertOrganizationInScope($request->user(), $assignment->organization_id);

        $policy = $this->workflow->createDraft($data, $request->user(), $request);

        return to_route('cafeteria.policies.show', $policy)
            ->with('flash', ['message' => __('cafeteria-policy.policy_saved'), 'type' => 'success']);
    }

    public function show(Request $request, CafeteriaServicePolicy $policy): Response
    {
        $this->assertOrganizationInScope($request->user(), $policy->organization_id);
        $policy->load(['organization', 'provider', 'network', 'cafeteria', 'assignment', 'supersedes']);
        $user = $request->user();

        $versions = CafeteriaServicePolicy::query()
            ->where('policy_group_id', $policy->policy_group_id)
            ->orderByDesc('version_no')
            ->get()
            ->map(fn (CafeteriaServicePolicy $v): array => [
                'id' => $v->id,
                'version_no' => $v->version_no,
                'daily_subsidy_amount' => (string) $v->daily_subsidy_amount,
                'effective_from' => $v->effective_from?->toDateString(),
                'effective_to' => $v->effective_to?->toDateString(),
                'status' => $v->status?->value,
            ]);

        return Inertia::render('Cafeteria/Policies/Show', [
            'policy' => [
                ...$this->summary($policy),
                ...$policy->only(CafeteriaServicePolicy::TERMS),
                'extra_scan_policy' => $policy->extra_scan_policy?->value,
                'notes' => $policy->notes,
                'supersedes' => $policy->supersedes ? ['id' => $policy->supersedes->id, 'version_no' => $policy->supersedes->version_no] : null,
                'created_by' => $policy->created_by,
                'submitted_at' => $policy->submitted_at?->toIso8601String(),
                'approved_at' => $policy->approved_at?->toIso8601String(),
                'cancellation_reason' => $policy->cancellation_reason,
            ],
            'versions' => $versions,
            'preview' => $this->workflow->preview($policy),
            'can' => [
                'edit' => $policy->status->isEditable() && $user->can('cafeteria_policies.update_draft'),
                'submit' => $policy->status->value === 'draft' && $user->can('cafeteria_policies.submit'),
                'review' => $policy->status->value === 'under_review' && $user->can('cafeteria_policies.review'),
                'approve' => $policy->status->value === 'under_review' && $user->can('cafeteria_policies.approve'),
                'activate' => $policy->status->value === 'approved' && $policy->effective_from->lte(today()) && $user->can('cafeteria_policies.activate'),
                'end' => in_array($policy->status->value, ['approved', 'active'], true) && $user->can('cafeteria_policies.end'),
                'cancel' => (in_array($policy->status->value, ['draft', 'under_review'], true)
                    || ($policy->status->value === 'approved' && $policy->effective_from->gt(today()))) && $user->can('cafeteria_policies.end'),
                'newVersion' => in_array($policy->status->value, ['approved', 'active'], true) && $user->can('cafeteria_policies.create'),
            ],
        ]);
    }

    public function edit(Request $request, CafeteriaServicePolicy $policy): Response|RedirectResponse
    {
        $this->assertOrganizationInScope($request->user(), $policy->organization_id);
        if (! $policy->status->isEditable()) {
            return to_route('cafeteria.policies.show', $policy);
        }

        return Inertia::render('Cafeteria/Policies/Form', [
            'policy' => [
                ...$policy->only(['id', 'cafeteria_service_assignment_id', 'cafeteria_id', 'notes', 'version_no', ...CafeteriaServicePolicy::TERMS]),
                'extra_scan_policy' => $policy->extra_scan_policy?->value,
                'daily_subsidy_amount' => (string) $policy->daily_subsidy_amount,
                'employee_contribution_amount' => (string) $policy->employee_contribution_amount,
                'provider_price' => (string) $policy->provider_price,
                'effective_from' => $policy->effective_from?->toDateString(),
                'effective_to' => $policy->effective_to?->toDateString(),
            ],
            'assignments' => $this->assignmentOptions($request),
            'cafeterias' => $this->cafeteriaOptions(),
            'defaults' => [],
        ]);
    }

    public function update(Request $request, CafeteriaServicePolicy $policy): RedirectResponse
    {
        $this->assertOrganizationInScope($request->user(), $policy->organization_id);
        $this->workflow->updateDraft($policy, $this->validated($request, creating: false), $request->user(), $request);

        return to_route('cafeteria.policies.show', $policy)
            ->with('flash', ['message' => __('cafeteria-policy.policy_saved'), 'type' => 'success']);
    }

    public function submit(Request $request, CafeteriaServicePolicy $policy): RedirectResponse
    {
        $this->assertOrganizationInScope($request->user(), $policy->organization_id);
        $this->workflow->submit($policy, $request->user(), $request);

        return back()->with('flash', ['message' => __('cafeteria-policy.policy_submitted'), 'type' => 'success']);
    }

    public function returnToDraft(Request $request, CafeteriaServicePolicy $policy): RedirectResponse
    {
        $this->assertOrganizationInScope($request->user(), $policy->organization_id);
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:1000']])['reason'] ?? null;
        $this->workflow->returnToDraft($policy, $request->user(), $reason, $request);

        return back()->with('flash', ['message' => __('cafeteria-policy.policy_returned'), 'type' => 'success']);
    }

    public function approve(Request $request, CafeteriaServicePolicy $policy): RedirectResponse
    {
        $this->assertOrganizationInScope($request->user(), $policy->organization_id);
        $this->workflow->approve($policy, $request->user(), $request);

        return back()->with('flash', ['message' => __('cafeteria-policy.policy_approved'), 'type' => 'success']);
    }

    public function activate(Request $request, CafeteriaServicePolicy $policy): RedirectResponse
    {
        $this->assertOrganizationInScope($request->user(), $policy->organization_id);
        $this->workflow->activate($policy, $request->user(), $request);

        return back()->with('flash', ['message' => __('cafeteria-policy.policy_activated'), 'type' => 'success']);
    }

    public function end(Request $request, CafeteriaServicePolicy $policy): RedirectResponse
    {
        $this->assertOrganizationInScope($request->user(), $policy->organization_id);
        $data = $request->validate(['effective_to' => ['required', 'date']]);
        $this->workflow->end($policy, Carbon::parse($data['effective_to']), $request->user(), $request);

        return back()->with('flash', ['message' => __('cafeteria-policy.policy_ended'), 'type' => 'success']);
    }

    public function cancel(Request $request, CafeteriaServicePolicy $policy): RedirectResponse
    {
        $this->assertOrganizationInScope($request->user(), $policy->organization_id);
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:1000']])['reason'] ?? null;
        $this->workflow->cancel($policy, $request->user(), $reason, $request);

        return back()->with('flash', ['message' => __('cafeteria-policy.policy_cancelled'), 'type' => 'success']);
    }

    public function newVersion(Request $request, CafeteriaServicePolicy $policy): RedirectResponse
    {
        $this->assertOrganizationInScope($request->user(), $policy->organization_id);
        $version = $this->workflow->createNewVersion($policy, $request->user(), $request);

        return to_route('cafeteria.policies.edit', $version)
            ->with('flash', ['message' => __('cafeteria-policy.policy_version_created'), 'type' => 'success']);
    }

    /** @return array<string, mixed> */
    private function summary(CafeteriaServicePolicy $p): array
    {
        return [
            'id' => $p->id,
            'policy_group_id' => $p->policy_group_id,
            'version_no' => $p->version_no,
            'organization' => $this->namePair($p->organization),
            'provider' => $this->namePair($p->provider, 'provider_code'),
            'network' => $this->namePair($p->network),
            'cafeteria' => $this->namePair($p->cafeteria),
            'scope_level' => $p->scopeLevel(),
            'daily_subsidy_amount' => (string) $p->daily_subsidy_amount,
            'employee_contribution_amount' => (string) $p->employee_contribution_amount,
            'provider_price' => (string) $p->provider_price,
            'currency_code' => $p->currency_code,
            'max_daily_uses' => $p->max_daily_uses,
            'allow_advance_usage' => $p->allow_advance_usage,
            'advance_max_days' => $p->advance_max_days,
            'extra_scan_policy' => $p->extra_scan_policy?->value,
            'working_days' => $p->enabledWeekdays(),
            'effective_from' => $p->effective_from?->toDateString(),
            'effective_to' => $p->effective_to?->toDateString(),
            'status' => $p->status?->value,
        ];
    }

    /** @return list<array<string, mixed>> active or pending assignments within scope, as scope choices */
    private function assignmentOptions(Request $request): array
    {
        return $this->withinOrganizationScope(CafeteriaServiceAssignment::query(), $request->user())
            ->whereIn('status', [CafeteriaGrantStatus::Active->value, CafeteriaGrantStatus::PendingApproval->value])
            ->with(['organization:id,code,name_en,name_am', 'provider:id,provider_code,name_en,name_am', 'network:id,code,name_en,name_am', 'cafeteria:id,code,name_en,name_am'])
            ->orderByDesc('effective_from')
            ->get()
            ->map(fn (CafeteriaServiceAssignment $a): array => [
                'id' => $a->id,
                'organization' => $this->namePair($a->organization),
                'provider' => $this->namePair($a->provider, 'provider_code'),
                'provider_id' => $a->provider_id,
                'network' => $this->namePair($a->network),
                'cafeteria_service_network_id' => $a->cafeteria_service_network_id,
                'cafeteria' => $this->namePair($a->cafeteria),
                'cafeteria_id' => $a->cafeteria_id,
                'status' => $a->status?->value,
                'effective_from' => $a->effective_from?->toDateString(),
            ])
            ->all();
    }

    /**
     * Operational defaults to prefill a new policy — never an amount: every
     * financial value is entered explicitly.
     *
     * @return array<string, mixed>
     */
    private function systemDefaults(CafeteriaSettingsService $settings): array
    {
        $weekend = ! $settings->getBool('closed_weekend_default');

        return [
            'currency_code' => (string) $settings->get('currency', 'ETB'),
            'max_daily_uses' => 1,
            'allow_advance_usage' => $settings->getBool('allow_upfront_weekday_usage'),
            'advance_max_days' => null,
            'extra_scan_policy' => $settings->get('excess_amount_mode') === 'employee_payable'
                ? CafeteriaExtraScanPolicy::EmployeePaid->value
                : CafeteriaExtraScanPolicy::Block->value,
            'monday_enabled' => true, 'tuesday_enabled' => true, 'wednesday_enabled' => true,
            'thursday_enabled' => true, 'friday_enabled' => true,
            'saturday_enabled' => $weekend && $settings->getBool('allow_saturday_service'),
            'sunday_enabled' => $weekend && $settings->getBool('allow_sunday_service'),
            'exclude_public_holidays' => $settings->getBool('exclude_public_holidays'),
            'block_employee_leave' => $settings->getBool('block_cafeteria_during_employee_leave'),
            'effective_from' => today()->addDay()->toDateString(),
        ];
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating): array
    {
        return $request->validate([
            'cafeteria_service_assignment_id' => [$creating ? 'required' : 'prohibited', 'uuid', 'exists:cafeteria_service_assignments,id'],
            'cafeteria_id' => [$creating ? 'nullable' : 'prohibited', 'uuid', 'exists:cafeteria_providers,id'],
            'daily_subsidy_amount' => ['required', 'numeric', 'min:0', 'max:1000000', 'decimal:0,2'],
            'employee_contribution_amount' => ['required', 'numeric', 'min:0', 'max:1000000', 'decimal:0,2'],
            'provider_price' => ['required', 'numeric', 'gt:0', 'max:1000000', 'decimal:0,2'],
            'currency_code' => ['required', 'string', 'size:3', 'alpha'],
            'max_daily_uses' => ['required', 'integer', 'min:1', 'max:5'],
            'allow_advance_usage' => ['boolean'],
            'advance_max_days' => ['nullable', 'integer', 'min:0', 'max:6'],
            'extra_scan_policy' => ['required', Rule::enum(CafeteriaExtraScanPolicy::class)],
            'monday_enabled' => ['boolean'], 'tuesday_enabled' => ['boolean'], 'wednesday_enabled' => ['boolean'],
            'thursday_enabled' => ['boolean'], 'friday_enabled' => ['boolean'], 'saturday_enabled' => ['boolean'], 'sunday_enabled' => ['boolean'],
            'exclude_public_holidays' => ['boolean'],
            'block_employee_leave' => ['boolean'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
    }
}

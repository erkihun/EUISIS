<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\DailyActivityReviewMode;
use App\Http\Controllers\Controller;
use App\Http\Requests\DailyActivity\StoreDailyActivityReviewerRequest;
use App\Models\DailyActivityReviewerAssignment;
use App\Models\DailyActivityReviewPolicy;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\Organization;
use App\Models\OrganizationUnit;
use App\Models\User;
use App\Services\DailyActivity\DailyActivityReviewerResolver;
use App\Services\DailyActivity\DailyActivityReviewPolicyService;
use App\Services\DailyActivity\DailyActivitySettings;
use App\Services\OrganizationScope\OrganizationScopeService;
use App\Support\DailyActivity\DailyActivityAbilities;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Daily Activities > Reviewer Assignments: who reviews whose daily activity.
 *
 * Operational, organization-scoped data, kept apart from the city-wide module
 * rules on the Settings page. Requires daily_activities.manage_reviewers, and
 * every list and write is confined to the actor's organization scope.
 * Assigning a reviewer grants no permission: only accounts that already hold
 * the review permission can be chosen.
 */
class DailyActivityReviewerController extends Controller
{
    private const EMPLOYEE_OPTION_LIMIT = 100;

    public function __construct(
        private readonly OrganizationScopeService $scope,
        private readonly DailyActivityReviewerResolver $reviewers,
        private readonly WriteAuditLogAction $audit,
        private readonly DailyActivitySettings $dailySettings,
        private readonly DailyActivityReviewPolicyService $reviewPolicy,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->can('daily_activities.manage_reviewers'), 403);

        $organizationId = is_string($request->query('organization_id')) && $this->scope->canAccessOrganization($user, $request->query('organization_id'))
            ? $request->query('organization_id')
            : null;
        $employeeSearch = is_string($request->query('employee_search')) ? mb_substr(trim($request->query('employee_search')), 0, 60) : '';

        return Inertia::render('DailyActivities/ReviewerAssignments', [
            'reviewers' => $this->assignments($user),
            'options' => $this->reviewerOptions($user, $organizationId, $employeeSearch),
            'selectedOrganizationId' => $organizationId,
            'employeeSearch' => $employeeSearch,
            'policies' => $this->policies($user),
            'cityDefaultMode' => $this->reviewPolicy->cityDefault()->value,
            'reviewModes' => DailyActivityReviewMode::values(),
            'can' => DailyActivityAbilities::for($user),
        ]);
    }

    /**
     * Set how much of one organization's daily activity needs review. Scoped
     * like reviewer assignments: only organizations the actor administers.
     */
    public function updatePolicy(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('daily_activities.manage_reviewers'), 403);
        $data = $request->validate([
            'organization_id' => ['required', 'uuid', 'exists:organizations,id'],
            'review_mode' => ['required', Rule::in(DailyActivityReviewMode::values())],
        ]);

        if (! $this->reviewers->canManageAssignmentsFor($request->user(), $data['organization_id'])) {
            throw ValidationException::withMessages(['organization_id' => __('daily-activities.reviewer_scope')]);
        }

        $policy = DailyActivityReviewPolicy::query()->firstOrNew(['organization_id' => $data['organization_id']]);
        $old = $policy->exists ? $policy->review_mode->value : DailyActivityReviewMode::Inherit->value;
        $policy->review_mode = DailyActivityReviewMode::from($data['review_mode']);
        $policy->forceFill(['updated_by' => $request->user()->getKey()])->save();

        $this->audit->execute(
            AuditEventType::DailyActivityReviewPolicyChanged,
            $request->user(),
            $policy,
            $policy->organization_id,
            ['review_mode' => $old],
            ['review_mode' => $policy->review_mode->value],
            request: $request,
        );

        return back()->with('success', __('daily-activities.review_policy_saved'));
    }

    public function store(StoreDailyActivityReviewerRequest $request): RedirectResponse
    {
        $actor = $request->user();
        $data = $request->validated();

        if (! $this->reviewers->canManageAssignmentsFor($actor, $data['organization_id'])) {
            throw ValidationException::withMessages(['organization_id' => __('daily-activities.reviewer_scope')]);
        }

        if (! empty($data['organization_unit_id'])
            && ! OrganizationUnit::query()->whereKey($data['organization_unit_id'])->where('organization_id', $data['organization_id'])->exists()) {
            throw ValidationException::withMessages(['organization_unit_id' => __('daily-activities.reviewer_unit_mismatch')]);
        }

        if (! empty($data['employee_id'])) {
            $inOrganization = EmployeeAssignment::query()
                ->where('employee_id', $data['employee_id'])
                ->where('organization_id', $data['organization_id'])
                ->exists();
            if (! $inOrganization) {
                throw ValidationException::withMessages(['employee_id' => __('daily-activities.reviewer_employee_mismatch')]);
            }

            $reviewer = User::query()->find($data['reviewer_user_id']);
            if ($reviewer?->employee?->id === $data['employee_id']) {
                throw ValidationException::withMessages(['employee_id' => __('daily-activities.reviewer_self')]);
            }
        }

        $assignment = DailyActivityReviewerAssignment::query()->create([
            'reviewer_user_id' => $data['reviewer_user_id'],
            'organization_id' => $data['organization_id'],
            'organization_unit_id' => $data['organization_unit_id'] ?? null,
            'include_sub_units' => (bool) ($data['include_sub_units'] ?? true),
            'employee_id' => $data['employee_id'] ?? null,
            'is_active' => true,
            'effective_from' => $data['effective_from'] ?? null,
            'effective_to' => $data['effective_to'] ?? null,
            'created_by' => $actor->getKey(),
        ]);

        $this->audit->execute(
            AuditEventType::DailyActivityReviewerAssigned,
            $actor,
            $assignment,
            $assignment->organization_id,
            null,
            $assignment->only(['reviewer_user_id', 'organization_id', 'organization_unit_id', 'include_sub_units', 'employee_id', 'effective_from', 'effective_to']),
            request: $request,
        );

        return back()->with('success', __('daily-activities.reviewer_added'));
    }

    /**
     * Ends an assignment rather than deleting it. Review authority stops at
     * once, and the row stays as the record of who could review whom and
     * when, which earlier decisions' reviewed_by still refers to.
     */
    public function destroy(Request $request, DailyActivityReviewerAssignment $assignment): RedirectResponse
    {
        abort_unless($this->reviewers->canManageAssignmentsFor($request->user(), $assignment->organization_id), 403);

        if (! $assignment->is_active) {
            return back()->with('success', __('daily-activities.reviewer_removed'));
        }

        $old = $assignment->only(['is_active', 'effective_from', 'effective_to']);
        $yesterday = $this->dailySettings->today()->subDay()->toDateString();
        $endsLater = $assignment->effective_to === null || $assignment->effective_to->toDateString() > $yesterday;
        $started = $assignment->effective_from === null || $assignment->effective_from->toDateString() <= $yesterday;

        $assignment->forceFill([
            'is_active' => false,
            // A scheduled assignment that never started keeps its dates; one
            // that ran is closed yesterday, so its period reads correctly.
            'effective_to' => $endsLater && $started ? $yesterday : $assignment->effective_to,
        ])->save();

        $this->audit->execute(
            AuditEventType::DailyActivityReviewerRemoved,
            $request->user(),
            $assignment,
            $assignment->organization_id,
            ['is_active' => $old['is_active'], 'effective_to' => $old['effective_to']?->toDateString()],
            ['is_active' => false, 'effective_to' => $assignment->effective_to?->toDateString(), 'reviewer_user_id' => $assignment->reviewer_user_id],
            request: $request,
        );

        return back()->with('success', __('daily-activities.reviewer_removed'));
    }

    /** @return array<int, array<string, mixed>> */
    private function assignments(User $user): array
    {
        $query = DailyActivityReviewerAssignment::query()
            ->with(['reviewer:id,name,email', 'organization:id,name_en,name_am', 'organizationUnit:id,name_en,name_am', 'employee:id,full_name,employee_number'])
            ->orderByDesc('created_at');

        $this->scope->applyOrganizationScope($query, $user);

        $today = $this->dailySettings->today()->toDateString();
        $assignments = $query->limit(500)->get();
        $covered = $this->coveredEmployees($assignments);

        return $assignments->map(fn (DailyActivityReviewerAssignment $a): array => [
            'status' => $this->assignmentStatus($a, $today),
            'covered_employees' => $covered[$a->id] ?? 0,
            'id' => $a->id,
            'reviewer' => $a->reviewer ? ['id' => $a->reviewer->id, 'name' => $a->reviewer->name, 'email' => $a->reviewer->email] : null,
            'organization' => $a->organization ? ['name_en' => $a->organization->name_en, 'name_am' => $a->organization->name_am] : null,
            'organization_unit' => $a->organizationUnit ? ['name_en' => $a->organizationUnit->name_en, 'name_am' => $a->organizationUnit->name_am] : null,
            'include_sub_units' => $a->include_sub_units,
            'employee' => $a->employee ? ['full_name' => $a->employee->full_name, 'employee_number' => $a->employee->employee_number] : null,
            'effective_from' => $a->effective_from?->toDateString(),
            'effective_to' => $a->effective_to?->toDateString(),
            'is_active' => $a->is_active,
        ])->sortBy(fn (array $row): int => ['active' => 0, 'scheduled' => 1, 'ended' => 2][$row['status']])->values()->all();
    }

    /**
     * Employees each assignment covers today (current placements), so an
     * administrator can see when one reviewer carries too many people. Counted
     * once per organization and unit, then summed over unit subtrees.
     *
     * @param  Collection<int, DailyActivityReviewerAssignment>  $assignments
     * @return array<string, int>
     */
    private function coveredEmployees($assignments): array
    {
        $organizationIds = $assignments->pluck('organization_id')->unique()->values()->all();
        if ($organizationIds === []) {
            return [];
        }

        $rows = EmployeeAssignment::query()->toBase()
            ->whereIn('organization_id', $organizationIds)
            ->where('is_current', true)
            ->selectRaw('organization_id, organization_unit_id, COUNT(*) as total')
            ->groupBy('organization_id', 'organization_unit_id')
            ->get();
        $byOrganization = [];
        $byUnit = [];
        foreach ($rows as $row) {
            $byOrganization[$row->organization_id] = ($byOrganization[$row->organization_id] ?? 0) + (int) $row->total;
            if ($row->organization_unit_id !== null) {
                $byUnit[$row->organization_unit_id] = (int) $row->total;
            }
        }

        $counts = [];
        foreach ($assignments as $assignment) {
            $counts[$assignment->id] = match (true) {
                $assignment->employee_id !== null => 1,
                $assignment->organization_unit_id === null => $byOrganization[$assignment->organization_id] ?? 0,
                default => array_sum(array_map(
                    fn (string $unitId): int => $byUnit[$unitId] ?? 0,
                    $assignment->include_sub_units ? $this->reviewers->subtree($assignment->organization_unit_id) : [$assignment->organization_unit_id],
                )),
            };
        }

        return $counts;
    }

    /** @return array<int, array<string, mixed>> organizations in scope that set their own policy */
    private function policies(User $user): array
    {
        $organizationIds = $this->scope->applyOrganizationScope(Organization::query(), $user, 'id')->limit(500)->pluck('id');

        return DailyActivityReviewPolicy::query()
            ->whereIn('organization_id', $organizationIds)
            ->where('review_mode', '!=', DailyActivityReviewMode::Inherit->value)
            ->with('organization:id,name_en,name_am')
            ->get()
            ->map(fn (DailyActivityReviewPolicy $policy): array => [
                'organization_id' => $policy->organization_id,
                'organization' => $policy->organization ? ['name_en' => $policy->organization->name_en, 'name_am' => $policy->organization->name_am] : null,
                'review_mode' => $policy->review_mode->value,
            ])
            ->sortBy(fn (array $row): string => (string) ($row['organization']['name_en'] ?? ''))
            ->values()->all();
    }

    /** active: can review today; scheduled: starts later; ended: no longer applies. */
    private function assignmentStatus(DailyActivityReviewerAssignment $assignment, string $today): string
    {
        if (! $assignment->is_active || ($assignment->effective_to !== null && $assignment->effective_to->toDateString() < $today)) {
            return 'ended';
        }

        return $assignment->effective_from !== null && $assignment->effective_from->toDateString() > $today ? 'scheduled' : 'active';
    }

    /** @return array<string, mixed> */
    private function reviewerOptions(User $user, ?string $organizationId, string $employeeSearch = ''): array
    {
        // Large institutions have tens of thousands of employees: list a short
        // alphabetical page, and find anyone else by name or number.
        $employees = $organizationId === null ? collect() : Employee::query()
            ->whereIn('id', EmployeeAssignment::query()->where('organization_id', $organizationId)->where('is_current', true)->select('employee_id'))
            ->when(mb_strlen($employeeSearch) >= 2, fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('full_name', ci_like_operator(), '%'.$employeeSearch.'%')
                ->orWhere('employee_number', ci_like_operator(), '%'.$employeeSearch.'%')))
            ->orderBy('full_name')
            ->limit(self::EMPLOYEE_OPTION_LIMIT + 1)
            ->get(['id', 'full_name', 'employee_number']);

        $organizations = $this->scope->applyOrganizationScope(Organization::query(), $user, 'id')
            ->orderBy('name_en')
            ->limit(500)
            ->get(['id', 'name_en', 'name_am']);

        return [
            'organizations' => $organizations->map(fn (Organization $o): array => ['id' => $o->id, 'name_en' => $o->name_en, 'name_am' => $o->name_am])->all(),
            'units' => $organizationId === null ? [] : OrganizationUnit::query()
                ->where('organization_id', $organizationId)
                ->orderBy('name_en')
                ->get(['id', 'name_en', 'name_am'])
                ->map(fn (OrganizationUnit $u): array => ['id' => $u->id, 'name_en' => $u->name_en, 'name_am' => $u->name_am])
                ->all(),
            'employees' => $employees->take(self::EMPLOYEE_OPTION_LIMIT)
                ->map(fn (Employee $e): array => ['id' => $e->id, 'full_name' => $e->full_name, 'employee_number' => $e->employee_number])
                ->values()->all(),
            'employeesTruncated' => $employees->count() > self::EMPLOYEE_OPTION_LIMIT,
            // Candidate reviewers: accounts that already hold review authority.
            'users' => $this->scope->applyUserScope(User::query(), $user)
                ->where('status', 'active')
                ->where(fn (Builder $q) => $q
                    ->whereHas('roles.permissions', fn (Builder $p) => $p->where('name', 'daily_activities.review'))
                    ->orWhereHas('permissions', fn (Builder $p) => $p->where('name', 'daily_activities.review')))
                ->orderBy('name')
                ->limit(500)
                ->get(['id', 'name', 'email'])
                ->map(fn (User $u): array => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email])
                ->all(),
        ];
    }
}

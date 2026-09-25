<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Audit\WriteAuditLogAction;
use App\Actions\SystemSettings\UpdateSystemSettingsGroupAction;
use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Http\Requests\DailyActivity\StoreDailyActivityReviewerRequest;
use App\Http\Requests\DailyActivity\UpdateDailyActivitySettingsRequest;
use App\Models\DailyActivityReviewerAssignment;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\Organization;
use App\Models\OrganizationUnit;
use App\Models\User;
use App\Services\DailyActivity\DailyActivityReviewerResolver;
use App\Services\OrganizationScope\OrganizationScopeService;
use App\Services\SystemSettings\SystemSettingsRegistry;
use App\Services\SystemSettings\SystemSettingsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Daily Activities > Settings.
 *
 * Two separate concerns on one page, under two separate permissions:
 *   - module rules (deadline, backdating, reminders) -> daily_activity_settings.*
 *   - who reviews whom                               -> daily_activities.manage_reviewers
 *
 * Neither grants permissions. Reviewer assignments are confined to the
 * actor's organization scope, re-checked on every write.
 */
class DailyActivitySettingController extends Controller
{
    public function __construct(
        private readonly SystemSettingsService $settings,
        private readonly OrganizationScopeService $scope,
        private readonly DailyActivityReviewerResolver $reviewers,
        private readonly WriteAuditLogAction $audit,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $canViewSettings = $user->can('daily_activity_settings.view') || $user->can('daily_activity_settings.update');
        $canManageReviewers = $user->can('daily_activities.manage_reviewers');
        abort_unless($canViewSettings || $canManageReviewers, 403);

        $organizationId = is_string($request->query('organization_id')) && $this->scope->canAccessOrganization($user, $request->query('organization_id'))
            ? $request->query('organization_id')
            : null;

        return Inertia::render('DailyActivities/Settings', [
            'fields' => $canViewSettings ? $this->settings->getGroupForAdmin(SystemSettingsRegistry::GROUP_DAILY_ACTIVITY) : [],
            'reviewers' => $canManageReviewers ? $this->assignments($user) : [],
            'options' => $canManageReviewers ? $this->reviewerOptions($user, $organizationId) : null,
            'selectedOrganizationId' => $organizationId,
            'can' => [
                'viewSettings' => $canViewSettings,
                'updateSettings' => $user->can('daily_activity_settings.update'),
                'manageReviewers' => $canManageReviewers,
            ],
        ]);
    }

    public function update(UpdateDailyActivitySettingsRequest $request, UpdateSystemSettingsGroupAction $updateGroup): RedirectResponse
    {
        $validated = $request->validated();
        if (isset($validated['work_week_days'])) {
            $validated['work_week_days'] = array_values(array_unique(array_map('strval', $validated['work_week_days'])));
        }

        // Audited by the shared settings action, like every other group.
        $updateGroup->execute(SystemSettingsRegistry::GROUP_DAILY_ACTIVITY, $validated, $request->user());

        return back()->with('success', __('daily-activities.settings_updated'));
    }

    public function storeReviewer(StoreDailyActivityReviewerRequest $request): RedirectResponse
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

    public function destroyReviewer(Request $request, DailyActivityReviewerAssignment $assignment): RedirectResponse
    {
        abort_unless($this->reviewers->canManageAssignmentsFor($request->user(), $assignment->organization_id), 403);

        $old = $assignment->only(['reviewer_user_id', 'organization_id', 'organization_unit_id', 'include_sub_units', 'employee_id']);
        $assignment->delete();

        $this->audit->execute(AuditEventType::DailyActivityReviewerRemoved, $request->user(), $assignment, $old['organization_id'], $old, null, request: $request);

        return back()->with('success', __('daily-activities.reviewer_removed'));
    }

    /** @return array<int, array<string, mixed>> */
    private function assignments(User $user): array
    {
        $query = DailyActivityReviewerAssignment::query()
            ->with(['reviewer:id,name,email', 'organization:id,name_en,name_am', 'organizationUnit:id,name_en,name_am', 'employee:id,full_name,employee_number'])
            ->orderByDesc('created_at');

        $this->scope->applyOrganizationScope($query, $user);

        return $query->limit(500)->get()->map(fn (DailyActivityReviewerAssignment $a): array => [
            'id' => $a->id,
            'reviewer' => $a->reviewer ? ['id' => $a->reviewer->id, 'name' => $a->reviewer->name, 'email' => $a->reviewer->email] : null,
            'organization' => $a->organization ? ['name_en' => $a->organization->name_en, 'name_am' => $a->organization->name_am] : null,
            'organization_unit' => $a->organizationUnit ? ['name_en' => $a->organizationUnit->name_en, 'name_am' => $a->organizationUnit->name_am] : null,
            'include_sub_units' => $a->include_sub_units,
            'employee' => $a->employee ? ['full_name' => $a->employee->full_name, 'employee_number' => $a->employee->employee_number] : null,
            'effective_from' => $a->effective_from?->toDateString(),
            'effective_to' => $a->effective_to?->toDateString(),
            'is_active' => $a->is_active,
        ])->all();
    }

    /** @return array<string, mixed> */
    private function reviewerOptions(User $user, ?string $organizationId): array
    {
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
            'employees' => $organizationId === null ? [] : Employee::query()
                ->whereIn('id', EmployeeAssignment::query()->where('organization_id', $organizationId)->where('is_current', true)->select('employee_id'))
                ->orderBy('full_name')
                ->limit(1000)
                ->get(['id', 'full_name', 'employee_number'])
                ->map(fn (Employee $e): array => ['id' => $e->id, 'full_name' => $e->full_name, 'employee_number' => $e->employee_number])
                ->all(),
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

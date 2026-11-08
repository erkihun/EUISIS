<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Services;

use App\Actions\Audit\WriteAuditLogAction;
use App\Backbone\Contracts\AssignmentContextReader;
use App\Backbone\Contracts\EmployeeDirectory;
use App\Backbone\Contracts\UnitDirectory;
use App\Backbone\Data\EmployeeIdentity;
use App\Enums\AuditEventType;
use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Feedback\Domain\Enums\PublicFeedbackHandlerType;
use Modules\Feedback\Domain\Models\EmployeeServiceFeedback;
use Modules\Feedback\Domain\Models\PublicCaseHandlingUnit;
use Modules\Feedback\Domain\Models\PublicCaseHandlingUnitMember;
use Modules\Feedback\Domain\Models\PublicFeedbackAssignment;

/**
 * Public Tip-off and complaint handling units (ADR 0034): an institution
 * picks one or more of its own work units to handle public cases and names
 * the staff who serve in each, every one registered with a signature. Cases
 * routed or assigned to such a unit reach its serving members.
 */
final class PublicCaseHandlingUnitService
{
    public function __construct(
        private readonly OrganizationScopeService $scope,
        private readonly UnitDirectory $units,
        private readonly EmployeeDirectory $employees,
        private readonly AssignmentContextReader $assignments,
        private readonly WriteAuditLogAction $audit,
    ) {}

    /** @param  array{organization_unit_id: string, case_type: string, stage?: string|null, description_en?: string|null, description_am?: string|null}  $data */
    public function designate(User $actor, array $data): PublicCaseHandlingUnit
    {
        if (($data['case_type'] ?? null) === 'complaint' && blank($data['organization_unit_id'] ?? null)) {
            return $this->designateCommittee($actor, $data);
        }
        $unit = $this->units->find((string) $data['organization_unit_id']);
        if ($unit === null || $unit->status !== 'active') {
            throw ValidationException::withMessages(['organization_unit_id' => __('service-feedback.handling_unit_inactive')]);
        }
        $this->assertManage($actor, $unit->organizationUuid);
        $caseType = (string) $data['case_type'];
        if (! in_array($caseType, PublicCaseHandlingUnit::CASE_TYPES, true)) {
            throw ValidationException::withMessages(['case_type' => __('validation.in', ['attribute' => 'case_type'])]);
        }
        if (PublicCaseHandlingUnit::query()->where('organization_unit_id', $unit->unitUuid)->where('case_type', $caseType)->exists()) {
            throw ValidationException::withMessages(['organization_unit_id' => __('service-feedback.handling_unit_exists')]);
        }
        // ADR 0035: a complaint unit says which hearing it serves; one appeal committee and one head's office per institution.
        $stage = $caseType === 'complaint' ? (string) ($data['stage'] ?? 'first_instance') : 'first_instance';
        if (! in_array($stage, PublicCaseHandlingUnit::STAGES, true)) {
            throw ValidationException::withMessages(['stage' => __('validation.in', ['attribute' => 'stage'])]);
        }
        if (in_array($stage, PublicCaseHandlingUnit::SINGLE_STAGES, true) && PublicCaseHandlingUnit::query()
            ->where('organization_id', $unit->organizationUuid)->where('case_type', $caseType)->where('stage', $stage)->where('status', 'active')->exists()) {
            throw ValidationException::withMessages(['stage' => __('service-feedback.stages.unit_stage_taken')]);
        }

        $designation = PublicCaseHandlingUnit::query()->create([
            'organization_id' => $unit->organizationUuid,
            'organization_unit_id' => $unit->unitUuid,
            'case_type' => $caseType,
            'stage' => $stage,
            'status' => 'active',
            'description_en' => $data['description_en'] ?? null,
            'description_am' => $data['description_am'] ?? null,
            'created_by' => $actor->getKey(),
        ]);
        $this->record(AuditEventType::ServiceFeedbackHandlingUnitSaved, $actor, $designation, ['organization_unit_id' => $unit->unitUuid, 'case_type' => $caseType, 'stage' => $stage, 'status' => 'active'], 'Public case handling unit designated');

        return $designation;
    }

    /**
     * ADR 0035 (owner follow-up): a complaint committee, appeal committee or
     * head's office is an institution body, not a work unit. Its members
     * come from any unit of the institution.
     *
     * @param  array{organization_id?: string, stage?: string|null, name_en?: string|null, name_am?: string|null, description_en?: string|null, description_am?: string|null}  $data
     */
    private function designateCommittee(User $actor, array $data): PublicCaseHandlingUnit
    {
        $organizationId = (string) ($data['organization_id'] ?? '');
        $this->assertManage($actor, $organizationId);
        $stage = (string) ($data['stage'] ?? 'first_instance');
        if (! in_array($stage, PublicCaseHandlingUnit::STAGES, true)) {
            throw ValidationException::withMessages(['stage' => __('validation.in', ['attribute' => 'stage'])]);
        }
        if (blank($data['name_am'] ?? null) && blank($data['name_en'] ?? null)) {
            throw ValidationException::withMessages(['name_am' => __('service-feedback.stages.committee_name_required')]);
        }
        if (in_array($stage, PublicCaseHandlingUnit::SINGLE_STAGES, true) && PublicCaseHandlingUnit::query()
            ->where('organization_id', $organizationId)->where('case_type', 'complaint')->where('stage', $stage)->where('status', 'active')->exists()) {
            throw ValidationException::withMessages(['stage' => __('service-feedback.stages.unit_stage_taken')]);
        }

        $designation = PublicCaseHandlingUnit::query()->create([
            'organization_id' => $organizationId,
            'organization_unit_id' => null,
            'case_type' => 'complaint',
            'stage' => $stage,
            'name_en' => filled($data['name_en'] ?? null) ? trim((string) $data['name_en']) : null,
            'name_am' => filled($data['name_am'] ?? null) ? trim((string) $data['name_am']) : null,
            'status' => 'active',
            'description_en' => $data['description_en'] ?? null,
            'description_am' => $data['description_am'] ?? null,
            'created_by' => $actor->getKey(),
        ]);
        $this->record(AuditEventType::ServiceFeedbackHandlingUnitSaved, $actor, $designation, ['committee' => true, 'case_type' => 'complaint', 'stage' => $stage, 'status' => 'active'], 'Public complaint committee formed');

        return $designation;
    }

    /** @param  array{status?: string, name_en?: string|null, name_am?: string|null, description_en?: string|null, description_am?: string|null}  $data */
    public function update(PublicCaseHandlingUnit $designation, User $actor, array $data): PublicCaseHandlingUnit
    {
        $this->assertManage($actor, (string) $designation->organization_id);
        $changes = array_intersect_key($data, array_flip($designation->isCommittee() ? ['status', 'name_en', 'name_am', 'description_en', 'description_am'] : ['status', 'description_en', 'description_am']));
        if ($designation->isCommittee() && array_key_exists('name_am', $changes) && blank($changes['name_am']) && blank($changes['name_en'] ?? $designation->name_en)) {
            throw ValidationException::withMessages(['name_am' => __('service-feedback.stages.committee_name_required')]);
        }
        $designation->forceFill($changes)->save();
        $this->record(AuditEventType::ServiceFeedbackHandlingUnitSaved, $actor, $designation, $changes, 'Public case handling unit updated');

        return $designation;
    }

    /** @param  array{employee_id: string, responsibility: string, effective_from: string, effective_to?: string|null, appointment_reference?: string|null, signature: UploadedFile}  $data */
    public function addMember(PublicCaseHandlingUnit $designation, User $actor, array $data): PublicCaseHandlingUnitMember
    {
        $this->assertManage($actor, (string) $designation->organization_id);
        if (! $designation->isActive()) {
            throw ValidationException::withMessages(['employee_id' => __('service-feedback.handling_unit_inactive')]);
        }
        $from = Carbon::parse($data['effective_from'])->startOfDay();
        $to = filled($data['effective_to'] ?? null) ? Carbon::parse((string) $data['effective_to'])->startOfDay() : null;

        return DB::transaction(function () use ($designation, $actor, $data, $from, $to): PublicCaseHandlingUnitMember {
            PublicCaseHandlingUnit::query()->whereKey($designation->getKey())->lockForUpdate()->first();

            $employeeId = (string) $data['employee_id'];
            $employee = $this->employees->findByUuid($employeeId);
            if ($employee === null || $employee->status !== 'active') {
                throw ValidationException::withMessages(['employee_id' => __('service-feedback.handling_member_not_active')]);
            }
            // Members are the unit's own staff: placed in the unit or a unit beneath it.
            if (! $this->isPlacedIn($employeeId, $designation)) {
                throw ValidationException::withMessages(['employee_id' => __('service-feedback.handling_member_outside_unit')]);
            }
            if ($designation->members()->servingOn($from)->where('employee_id', $employeeId)->exists()) {
                throw ValidationException::withMessages(['employee_id' => __('service-feedback.handling_member_exists')]);
            }
            $this->assertOneHead($designation, (string) $data['responsibility'], $from, null);

            $member = PublicCaseHandlingUnitMember::query()->create([
                'handling_unit_id' => $designation->getKey(),
                'employee_id' => $employeeId,
                'responsibility' => $data['responsibility'],
                'effective_from' => $from->toDateString(),
                'effective_to' => $to?->toDateString(),
                'status' => 'active',
                'appointment_reference' => $data['appointment_reference'] ?? null,
                'appointed_by' => $actor->getKey(),
                ...$this->storeSignature($data['signature'], $actor),
            ]);
            $this->record(AuditEventType::ServiceFeedbackHandlingUnitMemberSaved, $actor, $designation, ['employee_id' => $employeeId, 'responsibility' => $data['responsibility'], 'effective_from' => $from->toDateString(), 'signature_sha256' => $member->signature_sha256], 'Public case handling unit member added');

            return $member;
        });
    }

    /** @param  array{responsibility: string, appointment_reference?: string|null, signature?: UploadedFile|null}  $data */
    public function updateMember(PublicCaseHandlingUnit $designation, PublicCaseHandlingUnitMember $member, User $actor, array $data): PublicCaseHandlingUnitMember
    {
        $this->assertManage($actor, (string) $designation->organization_id);
        abort_unless($member->handling_unit_id === $designation->getKey(), 404);
        if (! $member->isServing()) {
            throw ValidationException::withMessages(['member' => __('service-feedback.handling_member_ended')]);
        }
        $this->assertOneHead($designation, (string) $data['responsibility'], now()->startOfDay(), $member);

        $changes = [
            'responsibility' => $data['responsibility'],
            'appointment_reference' => ($data['appointment_reference'] ?? null) ?: null,
        ];
        $replaced = ($data['signature'] ?? null) instanceof UploadedFile;
        if ($replaced) {
            $changes += $this->storeSignature($data['signature'], $actor);
        }
        $member->forceFill($changes)->save();
        $this->record(AuditEventType::ServiceFeedbackHandlingUnitMemberSaved, $actor, $designation, ['employee_id' => $member->employee_id, 'responsibility' => $data['responsibility'], 'signature_replaced' => $replaced], 'Public case handling unit member updated');

        return $member;
    }

    public function endMember(PublicCaseHandlingUnit $designation, PublicCaseHandlingUnitMember $member, User $actor, string $reason): PublicCaseHandlingUnitMember
    {
        $this->assertManage($actor, (string) $designation->organization_id);
        abort_unless($member->handling_unit_id === $designation->getKey(), 404);
        $member->forceFill(['status' => 'inactive', 'effective_to' => now()->toDateString(), 'end_reason' => $reason])->save();
        $this->record(AuditEventType::ServiceFeedbackHandlingUnitMemberEnded, $actor, $designation, ['employee_id' => $member->employee_id, 'effective_to' => now()->toDateString()], $reason);

        return $member;
    }

    /**
     * What keeps a designated unit from working (translation keys).
     *
     * @return list<string>
     */
    public function problems(PublicCaseHandlingUnit $designation): array
    {
        if (! $designation->isActive()) {
            return ['service-feedback.handling_problem_inactive'];
        }
        $serving = $designation->members()->servingOn(now())->get();
        $problems = [];
        if ($serving->isEmpty()) {
            $problems[] = 'service-feedback.handling_problem_no_members';
        }
        if ($serving->where('responsibility', 'head')->count() !== 1) {
            $problems[] = 'service-feedback.handling_problem_one_head';
        }
        if ($serving->contains(fn (PublicCaseHandlingUnitMember $m): bool => ! $this->isPlacedIn((string) $m->employee_id, $designation))) {
            $problems[] = 'service-feedback.handling_problem_member_moved';
        }
        $withAccount = User::query()->where('status', 'active')->whereIn('employee_id', $serving->pluck('employee_id'))->pluck('employee_id')->map(fn ($id) => (string) $id)->all();
        if ($serving->contains(fn (PublicCaseHandlingUnitMember $m): bool => ! in_array((string) $m->employee_id, $withAccount, true))) {
            $problems[] = 'service-feedback.handling_problem_no_account';
        }

        return $problems;
    }

    /**
     * Work units an organization has designated, for one case type or any.
     *
     * @return list<string>
     */
    public function activeUnitIds(?string $organizationId, ?string $caseType = null): array
    {
        if ($organizationId === null) {
            return [];
        }

        return PublicCaseHandlingUnit::query()->where('organization_id', $organizationId)->where('status', 'active')->whereNotNull('organization_unit_id')
            ->when($caseType !== null, fn ($q) => $q->where('case_type', $caseType), fn ($q) => $q->whereIn('case_type', PublicCaseHandlingUnit::ROUTED_TYPES))
            ->distinct()->pluck('organization_unit_id')->map(fn ($id): string => (string) $id)->values()->all();
    }

    /**
     * The users who serve a unit designated for this case type today, or null
     * when it is not so designated (its placed staff handle it, as before ADR 0034).
     *
     * @return list<string>|null
     */
    public function servingUserIds(string $organizationUnitId, string $caseType): ?array
    {
        $designation = PublicCaseHandlingUnit::query()->where('organization_unit_id', $organizationUnitId)->where('case_type', $caseType)->where('status', 'active')->first();

        return $designation === null ? null : $this->servingUsersOf($designation);
    }

    /** ADR 0035: the users serving an institution committee today (none when it is inactive). */
    public function committeeUserIds(string $committeeId): array
    {
        $committee = PublicCaseHandlingUnit::query()->whereNull('organization_unit_id')->where('status', 'active')->find($committeeId);

        return $committee === null ? [] : $this->servingUsersOf($committee);
    }

    /** @return list<string> */
    private function servingUsersOf(PublicCaseHandlingUnit $designation): array
    {
        $employeeIds = $designation->members()->servingOn(now())->pluck('employee_id');

        return User::query()->where('status', 'active')->whereIn('employee_id', $employeeIds)->pluck('id')->map(fn ($id): string => (string) $id)->all();
    }

    /**
     * Institution committees the user serves on today.
     *
     * @return list<string> committee ids
     */
    public function committeeIdsServedBy(User $user): array
    {
        if ($user->employee_id === null) {
            return [];
        }

        return PublicCaseHandlingUnitMember::query()->servingOn(now())->where('employee_id', $user->employee_id)
            ->join('public_case_handling_units as u', 'u.id', '=', 'public_case_handling_unit_members.handling_unit_id')
            ->where('u.status', 'active')->whereNull('u.organization_unit_id')
            ->pluck('u.id')->map(fn ($id): string => (string) $id)->all();
    }

    /**
     * Designated units the user serves in today.
     *
     * @return list<string> organization unit ids
     */
    public function unitIdsServedBy(User $user): array
    {
        if ($user->employee_id === null) {
            return [];
        }

        return PublicCaseHandlingUnitMember::query()->servingOn(now())->where('employee_id', $user->employee_id)
            ->join('public_case_handling_units as u', 'u.id', '=', 'public_case_handling_unit_members.handling_unit_id')
            ->where('u.status', 'active')->whereNotNull('u.organization_unit_id')
            ->pluck('u.organization_unit_id')->map(fn ($id): string => (string) $id)->all();
    }

    /**
     * Work units whose cases this user handles: the designated units they
     * serve in, and their own unit while it is not designated (a designated
     * unit's cases go to its named members only).
     *
     * @return list<string>
     */
    public function handledUnitIdsFor(User $user): array
    {
        $ids = $this->unitIdsServedBy($user);
        $own = $user->employee_id !== null ? $this->assignments->currentFor((string) $user->employee_id)?->organizationUnitUuid : null;
        if ($own !== null && ! PublicCaseHandlingUnit::query()->where('organization_unit_id', $own)->where('status', 'active')->exists()) {
            $ids[] = $own;
        }

        return array_values(array_unique($ids));
    }

    /**
     * Who may work a case in its current hands (ADR 0034): once a case is with
     * a designated unit, its serving members; once the head has handed it to
     * one member, that member and the unit's head. Coordinators (assign or
     * reassign) always may. Cases outside the designated units keep the
     * earlier rule: anyone with the permission in scope.
     */
    public function mayWork(User $user, EmployeeServiceFeedback $submission): bool
    {
        if ($user->hasAnyPermission(['service_feedback.assign', 'service_feedback.reassign'])) {
            return true;
        }
        $assignment = $submission->currentAssignment()->first();
        if ($assignment === null) {
            return true;
        }
        $type = $submission->type->value;
        $userId = (string) $user->getKey();

        return match ($assignment->handler_type) {
            PublicFeedbackHandlerType::OrganizationUnit => ($members = $this->servingUserIds((string) $assignment->handler_id, $type)) === null || in_array($userId, $members, true),
            PublicFeedbackHandlerType::User => $this->activeUnitIds($submission->organization_id, $type) === []
                || $userId === (string) $assignment->handler_id
                || $this->isHeadOver($user, (string) $assignment->handler_id, $type),
            PublicFeedbackHandlerType::Role => true,
            PublicFeedbackHandlerType::Committee => in_array($userId, $this->committeeUserIds((string) $assignment->handler_id), true),
        };
    }

    /**
     * Owner decision 2026-11-08: in an institution that has a Tip-off or
     * Complaint Unit, a handler's lists show only the cases in their hands —
     * assigned to them, to their role, to their unit, or handed by them as
     * head to one of their members. Coordinators (assign/reassign) and
     * city-wide accounts see every case in scope; service feedback and
     * institutions without such a unit are unchanged.
     *
     * @template TBuilder of \Illuminate\Contracts\Database\Eloquent\Builder
     *
     * @param  TBuilder  $query  over employee_service_feedback
     * @return TBuilder
     */
    public function limitToHandled($query, User $user)
    {
        if ($this->seesEverything($user)) {
            return $query;
        }
        $designated = PublicCaseHandlingUnit::query()->where('status', 'active')->whereIn('case_type', PublicCaseHandlingUnit::ROUTED_TYPES)
            ->get(['organization_id', 'case_type'])->groupBy('case_type')->map(fn ($rows) => $rows->pluck('organization_id')->unique()->values()->all());
        if ($designated->isEmpty()) {
            return $query;
        }
        $handled = $this->handledSubmissionIds($user);

        return $query->where(function ($w) use ($designated, $handled): void {
            $w->whereNotIn('type', $designated->keys()->all());
            foreach ($designated as $type => $organizations) {
                $w->orWhere(fn ($t) => $t->where('type', $type)->where(fn ($o) => $o->whereNull('organization_id')->orWhereNotIn('organization_id', $organizations)->orWhereIn('id', $handled)));
            }
        });
    }

    /** Whether this one case passes the limit above (for the case page). */
    public function canSee(User $user, EmployeeServiceFeedback $submission): bool
    {
        return $this->seesEverything($user)
            || $this->limitToHandled(EmployeeServiceFeedback::query()->whereKey($submission->getKey()), $user)->exists();
    }

    private function seesEverything(User $user): bool
    {
        return $user->hasAnyPermission(['service_feedback.assign', 'service_feedback.reassign'])
            || $this->scope->isUnrestricted($user);
    }

    /** Open assignments in this user's hands: in person, by role, by unit, or handed by them as head to a member. */
    private function handledSubmissionIds(User $user): Builder
    {
        $roleIds = $user->roles->pluck('id')->map(fn ($id): string => (string) $id)->all();
        $unitIds = $this->handledUnitIdsFor($user);
        $committeeIds = $this->committeeIdsServedBy($user);
        $userIds = [(string) $user->getKey()];
        if ($user->employee_id !== null) {
            $headed = PublicCaseHandlingUnit::query()->where('status', 'active')
                ->whereHas('members', fn ($q) => $q->servingOn(now())->where('responsibility', 'head')->where('employee_id', $user->employee_id))->get();
            foreach ($headed as $designation) {
                $userIds = [...$userIds, ...array_column($this->memberUsers($designation), 'id')];
            }
        }

        return PublicFeedbackAssignment::query()->whereNull('ended_at')->select('submission_id')
            ->where(function ($q) use ($roleIds, $unitIds, $userIds, $committeeIds): void {
                $q->where(fn ($q) => $q->where('handler_type', PublicFeedbackHandlerType::User->value)->whereIn('handler_id', array_values(array_unique($userIds))));
                if ($roleIds !== []) {
                    $q->orWhere(fn ($q) => $q->where('handler_type', PublicFeedbackHandlerType::Role->value)->whereIn('handler_id', $roleIds));
                }
                if ($unitIds !== []) {
                    $q->orWhere(fn ($q) => $q->where('handler_type', PublicFeedbackHandlerType::OrganizationUnit->value)->whereIn('handler_id', $unitIds));
                }
                if ($committeeIds !== []) {
                    $q->orWhere(fn ($q) => $q->where('handler_type', PublicFeedbackHandlerType::Committee->value)->whereIn('handler_id', $committeeIds));
                }
            });
    }

    /** ADR 0035: the committee whose head this user is, when the case is with that committee. */
    public function headedCommittee(User $user, string $committeeId): ?PublicCaseHandlingUnit
    {
        if ($user->employee_id === null) {
            return null;
        }
        $committee = PublicCaseHandlingUnit::query()->whereNull('organization_unit_id')->where('status', 'active')->find($committeeId);

        return $committee !== null && $committee->members()->servingOn(now())->where('responsibility', 'head')->where('employee_id', $user->employee_id)->exists()
            ? $committee : null;
    }

    /** The designated unit (for this case type) whose head this user is, when the case's unit is that one. */
    public function headedUnit(User $user, string $organizationUnitId, string $caseType): ?PublicCaseHandlingUnit
    {
        if ($user->employee_id === null) {
            return null;
        }
        $designation = PublicCaseHandlingUnit::query()->where('organization_unit_id', $organizationUnitId)->where('case_type', $caseType)->where('status', 'active')->first();

        return $designation !== null && $designation->members()->servingOn(now())->where('responsibility', 'head')->where('employee_id', $user->employee_id)->exists()
            ? $designation : null;
    }

    /**
     * The unit's serving members who have an account: whom its head can hand a case to.
     *
     * @return list<array{id: string, name: string}>
     */
    public function memberUsers(PublicCaseHandlingUnit $designation): array
    {
        $employeeIds = $designation->members()->servingOn(now())->pluck('employee_id');

        return User::query()->where('status', 'active')->whereIn('employee_id', $employeeIds)->orderBy('name')->get(['id', 'name'])
            ->map(fn (User $u): array => ['id' => (string) $u->getKey(), 'name' => (string) $u->name])->values()->all();
    }

    /** Whether $head heads a designated unit (this case type) in which the member user serves. */
    private function isHeadOver(User $head, string $memberUserId, string $caseType): bool
    {
        $member = User::query()->find($memberUserId);
        if ($head->employee_id === null || $member?->employee_id === null) {
            return false;
        }

        return PublicCaseHandlingUnit::query()->where('case_type', $caseType)->where('status', 'active')
            ->whereHas('members', fn ($q) => $q->servingOn(now())->where('responsibility', 'head')->where('employee_id', $head->employee_id))
            ->whereHas('members', fn ($q) => $q->servingOn(now())->where('employee_id', $member->employee_id))
            ->exists();
    }

    public function isDesignated(string $organizationUnitId): bool
    {
        return PublicCaseHandlingUnit::query()->where('organization_unit_id', $organizationUnitId)->where('status', 'active')->exists();
    }

    /**
     * The staff who can become members: active employees placed in the unit or
     * a unit beneath it, less those already serving in it.
     *
     * @return list<EmployeeIdentity>
     */
    public function candidates(PublicCaseHandlingUnit $designation): array
    {
        $serving = $designation->members()->servingOn(now())->pluck('employee_id')->map(fn ($id): string => (string) $id)->all();

        $units = $designation->isCommittee()
            ? array_map(fn ($unit): string => $unit->unitUuid, $this->units->forOrganization((string) $designation->organization_id, false))
            : $this->subtree((string) $designation->organization_id, (string) $designation->organization_unit_id);

        return array_values(array_filter(
            $this->employees->currentlyIn($units, 2000),
            fn ($employee): bool => ! in_array($employee->employeeUuid, $serving, true),
        ));
    }

    public function isPlacedIn(string $employeeId, PublicCaseHandlingUnit $designation): bool
    {
        $context = $this->assignments->currentFor($employeeId);
        if ($context === null || $context->organizationUuid !== $designation->organization_id) {
            return false;
        }
        // ADR 0035: a committee member works anywhere in the institution.
        if ($designation->isCommittee()) {
            return true;
        }
        if ($context->organizationUnitUuid === null) {
            return false;
        }

        return in_array($context->organizationUnitUuid, $this->subtree((string) $designation->organization_id, (string) $designation->organization_unit_id), true);
    }

    public function canManage(User $user, ?string $organizationId): bool
    {
        // The routing administrators set the units up (ADR 0034), with the same institution scope.
        if (! $user->hasAnyPermission([PublicFeedbackRoutingService::PERMISSION])) {
            return false;
        }

        return $this->scope->isUnrestricted($user)
            || ($organizationId !== null && $this->scope->canAccessOrganization($user, $organizationId));
    }

    /** @return list<string> the unit and every unit beneath it */
    private function subtree(string $organizationId, string $unitId): array
    {
        $children = [];
        foreach ($this->units->forOrganization($organizationId, false) as $unit) {
            if ($unit->parentUnitUuid !== null) {
                $children[$unit->parentUnitUuid][] = $unit->unitUuid;
            }
        }
        $ids = [$unitId];
        for ($i = 0; $i < count($ids); $i++) {
            foreach ($children[$ids[$i]] ?? [] as $child) {
                if (! in_array($child, $ids, true)) {
                    $ids[] = $child;
                }
            }
        }

        return $ids;
    }

    private function assertOneHead(PublicCaseHandlingUnit $designation, string $responsibility, Carbon $from, ?PublicCaseHandlingUnitMember $self): void
    {
        if ($responsibility !== 'head') {
            return;
        }
        $other = $designation->members()->servingOn($from)->where('responsibility', 'head')
            ->when($self !== null, fn ($q) => $q->whereKeyNot($self->getKey()))->exists();
        if ($other) {
            throw ValidationException::withMessages(['responsibility' => __('service-feedback.handling_one_head')]);
        }
    }

    /** @return array{signature_path: string, signature_sha256: string, signature_recorded_at: Carbon, signature_recorded_by: int|string|null} */
    private function storeSignature(UploadedFile $upload, User $actor): array
    {
        $extension = strtolower($upload->getClientOriginalExtension() ?: ($upload->extension() ?: 'png'));
        $extension = $extension === 'jpeg' ? 'jpg' : $extension;
        $contents = (string) file_get_contents($upload->getRealPath());
        $path = 'employee-signatures/'.Str::uuid().'.'.$extension;
        Storage::disk('local')->put($path, $contents);

        return [
            'signature_path' => $path,
            'signature_sha256' => hash('sha256', $contents),
            'signature_recorded_at' => now(),
            'signature_recorded_by' => $actor->getKey(),
        ];
    }

    private function assertManage(User $actor, string $organizationId): void
    {
        abort_unless($this->canManage($actor, $organizationId), 403);
    }

    /** @param  array<string, mixed>  $values */
    private function record(AuditEventType $event, User $actor, PublicCaseHandlingUnit $designation, array $values, string $reason): void
    {
        $this->audit->execute(
            eventType: $event,
            actor: $actor,
            auditable: $designation,
            organizationId: $designation->organization_id,
            newValues: $values,
            reason: $reason,
        );
    }
}

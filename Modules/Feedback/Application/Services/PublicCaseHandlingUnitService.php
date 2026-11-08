<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Services;

use App\Actions\Audit\WriteAuditLogAction;
use App\Backbone\Contracts\AssignmentContextReader;
use App\Backbone\Contracts\EmployeeDirectory;
use App\Backbone\Contracts\UnitDirectory;
use App\Enums\AuditEventType;
use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Feedback\Domain\Models\PublicCaseHandlingUnit;
use Modules\Feedback\Domain\Models\PublicCaseHandlingUnitMember;

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

    /** @param  array{organization_unit_id: string, case_type: string, description_en?: string|null, description_am?: string|null}  $data */
    public function designate(User $actor, array $data): PublicCaseHandlingUnit
    {
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

        $designation = PublicCaseHandlingUnit::query()->create([
            'organization_id' => $unit->organizationUuid,
            'organization_unit_id' => $unit->unitUuid,
            'case_type' => $caseType,
            'status' => 'active',
            'description_en' => $data['description_en'] ?? null,
            'description_am' => $data['description_am'] ?? null,
            'created_by' => $actor->getKey(),
        ]);
        $this->record(AuditEventType::ServiceFeedbackHandlingUnitSaved, $actor, $designation, ['organization_unit_id' => $unit->unitUuid, 'case_type' => $caseType, 'status' => 'active'], 'Public case handling unit designated');

        return $designation;
    }

    /** @param  array{status?: string, description_en?: string|null, description_am?: string|null}  $data */
    public function update(PublicCaseHandlingUnit $designation, User $actor, array $data): PublicCaseHandlingUnit
    {
        $this->assertManage($actor, (string) $designation->organization_id);
        $changes = array_intersect_key($data, array_flip(['status', 'description_en', 'description_am']));
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

        return PublicCaseHandlingUnit::query()->where('organization_id', $organizationId)->where('status', 'active')
            ->when($caseType !== null, fn ($q) => $q->where('case_type', $caseType))
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
        if ($designation === null) {
            return null;
        }
        $employeeIds = $designation->members()->servingOn(now())->pluck('employee_id');

        return User::query()->where('status', 'active')->whereIn('employee_id', $employeeIds)->pluck('id')->map(fn ($id): string => (string) $id)->all();
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
            ->where('u.status', 'active')
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

    public function isDesignated(string $organizationUnitId): bool
    {
        return PublicCaseHandlingUnit::query()->where('organization_unit_id', $organizationUnitId)->where('status', 'active')->exists();
    }

    public function isPlacedIn(string $employeeId, PublicCaseHandlingUnit $designation): bool
    {
        $context = $this->assignments->currentFor($employeeId);
        if ($context === null || $context->organizationUnitUuid === null || $context->organizationUuid !== $designation->organization_id) {
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

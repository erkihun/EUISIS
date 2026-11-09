<?php

declare(strict_types=1);

namespace Modules\Grievance\Application\Services;

use App\Enums\AuditEventType;
use App\Models\Employee;
use App\Models\OrganizationUnit;
use App\Models\User;
use App\Services\Legal\StatutoryCommitteeRules;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Grievance\Domain\Enums\GrievanceUnitEndReason;
use Modules\Grievance\Domain\Enums\GrievanceUnitResponsibility;
use Modules\Grievance\Domain\Models\GrievanceHandlerUnit;
use Modules\Grievance\Domain\Models\GrievanceHandlerUnitMember;

/**
 * Grievance Teams and Directorates (ADR 0029): designate an existing
 * organization unit, and name its members with their Regulation 201/2018
 * responsibility and a signature. Nothing in the organization structure is
 * created; terms are ended, never deleted.
 */
final class GrievanceHandlerUnitService
{
    public function __construct(
        private readonly GrievanceHandlerRegistry $handlers,
        private readonly GrievanceCaseAccessService $access,
        private readonly OrganizationScopeService $scope,
        private readonly GrievanceAudit $audit,
    ) {}

    // ── Designation ──────────────────────────────────────────────────────────

    /** @param  array{organization_unit_id: string, kind: string, description_en?: string|null, description_am?: string|null}  $data */
    public function designate(User $actor, array $data): GrievanceHandlerUnit
    {
        $unit = OrganizationUnit::query()->find($data['organization_unit_id']);
        if ($unit === null || ($unit->status?->value ?? $unit->status) !== 'active') {
            throw ValidationException::withMessages(['organization_unit_id' => __('grievances.errors.handler_inactive')]);
        }
        $this->assertManage($actor, (string) $unit->organization_id);
        if (! in_array($data['kind'], GrievanceHandlerUnit::KINDS, true)) {
            throw ValidationException::withMessages(['kind' => __('validation.in', ['attribute' => 'kind'])]);
        }
        if (GrievanceHandlerUnit::query()->where('organization_unit_id', $unit->getKey())->exists()) {
            throw ValidationException::withMessages(['organization_unit_id' => __('grievances.errors.unit_already_designated')]);
        }
        $this->assertSingleDirectorate($data['kind'], null);

        $designation = GrievanceHandlerUnit::query()->create([
            'organization_id' => $unit->organization_id,
            'organization_unit_id' => $unit->getKey(),
            'kind' => $data['kind'],
            'status' => 'active',
            'description_en' => $data['description_en'] ?? null,
            'description_am' => $data['description_am'] ?? null,
            'created_by' => $actor->getKey(),
        ]);
        $this->audit->record(AuditEventType::GrievanceHandlerUnitDesignated, $actor, $designation, ['organization_unit_id' => $unit->getKey(), 'kind' => $data['kind']]);

        return $designation;
    }

    /** @param  array{kind?: string, status?: string, description_en?: string|null, description_am?: string|null}  $data */
    public function update(GrievanceHandlerUnit $designation, User $actor, array $data): GrievanceHandlerUnit
    {
        $this->assertManage($actor, (string) $designation->organization_id);
        $changes = array_intersect_key($data, array_flip(['kind', 'status', 'description_en', 'description_am']));
        if (isset($changes['kind']) && ! in_array($changes['kind'], GrievanceHandlerUnit::KINDS, true)) {
            throw ValidationException::withMessages(['kind' => __('validation.in', ['attribute' => 'kind'])]);
        }
        if (isset($changes['status']) && ! in_array($changes['status'], ['active', 'inactive'], true)) {
            throw ValidationException::withMessages(['status' => __('validation.in', ['attribute' => 'status'])]);
        }
        if (($changes['status'] ?? $designation->status) === 'active') {
            $this->assertSingleDirectorate((string) ($changes['kind'] ?? $designation->kind), $designation);
        }
        $old = $designation->only(array_keys($changes));
        $designation->forceFill($changes)->save();
        $this->audit->record(AuditEventType::GrievanceHandlerUnitUpdated, $actor, $designation, $changes, $old);

        return $designation;
    }

    // ── Members ──────────────────────────────────────────────────────────────

    /** @param  array{employee_id: string, responsibility: string, effective_from: string, effective_to?: string|null, appointment_reference?: string|null, signature?: mixed, signature_path?: string|null}  $data */
    public function addMember(GrievanceHandlerUnit $designation, User $actor, array $data): GrievanceHandlerUnitMember
    {
        $this->assertManageMembers($actor, $designation);
        if (! $designation->isActive()) {
            throw ValidationException::withMessages(['employee_id' => __('grievances.errors.handler_inactive')]);
        }
        $responsibility = GrievanceUnitResponsibility::from($data['responsibility']);
        $from = Carbon::parse($data['effective_from'])->startOfDay();
        $to = filled($data['effective_to'] ?? null) ? Carbon::parse((string) $data['effective_to'])->startOfDay() : null;

        return DB::transaction(function () use ($designation, $actor, $data, $responsibility, $from, $to): GrievanceHandlerUnitMember {
            GrievanceHandlerUnit::query()->whereKey($designation->getKey())->lockForUpdate()->first();

            $employee = Employee::query()->find($data['employee_id']);
            if ($employee === null || ($employee->status?->value ?? $employee->status) !== 'active') {
                throw ValidationException::withMessages(['employee_id' => __('grievances.errors.employee_not_active')]);
            }
            // Members are the unit's own staff: placed in the unit or a unit beneath it.
            if (! in_array((string) $designation->organization_unit_id, $this->handlers->placementOf($employee)['unit_ids'], true)) {
                throw ValidationException::withMessages(['employee_id' => __('grievances.errors.member_outside_unit')]);
            }
            // Regulation 201/2018 Art. 6(2), as for committee members.
            if (app(StatutoryCommitteeRules::class)->employeeEligibility((string) $employee->getKey(), $from)['problems'] !== []) {
                throw ValidationException::withMessages(['employee_id' => __('grievances.errors.member_disciplined')]);
            }
            if ($designation->members()->servingOn($from)->where('employee_id', $employee->getKey())->exists()) {
                throw ValidationException::withMessages(['employee_id' => __('grievances.errors.already_member')]);
            }
            $this->assertOneHead($designation, $responsibility, $from, null);

            // ADR 0028/0029: registered together with the signature image.
            $path = $this->storeSignature($employee, $data['signature'] ?? null, $data['signature_path'] ?? null);
            $disk = Storage::disk('local');

            $member = GrievanceHandlerUnitMember::query()->create([
                'handler_unit_id' => $designation->getKey(),
                'employee_id' => $employee->getKey(),
                'responsibility' => $responsibility->value,
                'effective_from' => $from->toDateString(),
                'effective_to' => $to?->toDateString(),
                'status' => 'active',
                'appointment_reference' => $data['appointment_reference'] ?? null,
                'appointed_by' => $actor->getKey(),
                'signature_path' => $path,
                'signature_sha256' => $disk->exists($path) ? hash('sha256', (string) $disk->get($path)) : null,
                'signature_recorded_at' => now(),
                'signature_recorded_by' => $actor->getKey(),
            ]);
            $this->audit->record(AuditEventType::GrievanceHandlerUnitMemberAdded, $actor, $designation, ['employee_id' => $employee->getKey(), 'responsibility' => $responsibility->value, 'effective_from' => $from->toDateString(), 'signature_sha256' => $member->signature_sha256]);

            return $member;
        });
    }

    /** @param  array{responsibility?: string, effective_from?: string, effective_to?: string|null, appointment_reference?: string|null, signature?: mixed}  $data */
    public function updateMember(GrievanceHandlerUnit $designation, GrievanceHandlerUnitMember $member, User $actor, array $data): GrievanceHandlerUnitMember
    {
        $this->assertManageMembers($actor, $designation);
        if ($member->handler_unit_id !== $designation->getKey()) {
            $this->access->authorize(false);
        }
        if (! $member->isActive()) {
            throw ValidationException::withMessages(['member' => __('grievances.errors.member_term_ended')]);
        }

        return DB::transaction(function () use ($designation, $member, $actor, $data): GrievanceHandlerUnitMember {
            GrievanceHandlerUnit::query()->whereKey($designation->getKey())->lockForUpdate()->first();
            $member = GrievanceHandlerUnitMember::query()->whereKey($member->getKey())->lockForUpdate()->firstOrFail();

            $responsibility = isset($data['responsibility']) ? GrievanceUnitResponsibility::from((string) $data['responsibility']) : $member->responsibilityEnum();
            $from = Carbon::parse((string) ($data['effective_from'] ?? $member->effective_from))->startOfDay();
            $to = array_key_exists('effective_to', $data)
                ? (filled($data['effective_to']) ? Carbon::parse((string) $data['effective_to'])->startOfDay() : null)
                : ($member->effective_to ? Carbon::parse((string) $member->effective_to)->startOfDay() : null);
            if ($to !== null && $to->lte($from)) {
                throw ValidationException::withMessages(['effective_to' => __('validation.after', ['attribute' => 'effective_to', 'date' => $from->toDateString()])]);
            }
            $this->assertOneHead($designation, $responsibility, $from, $member);

            $changes = [
                'responsibility' => $responsibility->value,
                'effective_from' => $from->toDateString(),
                'effective_to' => $to?->toDateString(),
                'appointment_reference' => array_key_exists('appointment_reference', $data) ? ($data['appointment_reference'] ?: null) : $member->appointment_reference,
            ];
            $replaced = false;
            if (($data['signature'] ?? null) instanceof UploadedFile && $member->employee !== null) {
                $path = $this->storeSignature($member->employee, $data['signature'], null);
                $disk = Storage::disk('local');
                $changes += [
                    'signature_path' => $path,
                    'signature_sha256' => $disk->exists($path) ? hash('sha256', (string) $disk->get($path)) : null,
                    'signature_recorded_at' => now(),
                    'signature_recorded_by' => $actor->getKey(),
                ];
                $replaced = true;
            }
            $member->forceFill($changes)->save();
            $this->audit->record(AuditEventType::GrievanceHandlerUnitMemberUpdated, $actor, $designation, ['employee_id' => $member->employee_id, 'responsibility' => $responsibility->value, 'effective_from' => $from->toDateString(), 'effective_to' => $to?->toDateString(), 'signature_replaced' => $replaced]);

            return $member->refresh();
        });
    }

    /**
     * End a term with its reason: moved to another institution or unit,
     * retired, dismissed, or other (a note is then required).
     */
    public function endMember(GrievanceHandlerUnit $designation, GrievanceHandlerUnitMember $member, User $actor, GrievanceUnitEndReason $reason, ?string $note = null, ?string $on = null): GrievanceHandlerUnitMember
    {
        $this->assertManageMembers($actor, $designation);
        if ($member->handler_unit_id !== $designation->getKey()) {
            $this->access->authorize(false);
        }
        if ($reason === GrievanceUnitEndReason::Other && trim((string) $note) === '') {
            throw ValidationException::withMessages(['reason' => __('grievances.errors.reason_required')]);
        }
        $end = Carbon::parse($on ?? now())->startOfDay();
        $member->forceFill(['status' => 'inactive', 'effective_to' => $end->toDateString(), 'end_reason_code' => $reason->value, 'end_reason' => $note ?: null])->save();
        $this->audit->record(AuditEventType::GrievanceHandlerUnitMemberEnded, $actor, $designation, ['employee_id' => $member->employee_id, 'effective_to' => $end->toDateString(), 'end_reason_code' => $reason->value], null, $note);

        return $member;
    }

    // ── Reading ──────────────────────────────────────────────────────────────

    /**
     * What keeps a designated unit from working as intended (translation keys).
     *
     * @return list<string>
     */
    public function problems(GrievanceHandlerUnit $designation): array
    {
        if (! $designation->isActive()) {
            return ['grievances.errors.unit_designation_inactive'];
        }
        $serving = $designation->members()->servingOn(now())->with('employee')->get();
        $problems = [];
        if ($serving->isEmpty()) {
            $problems[] = 'grievances.errors.unit_has_no_members';
        }
        if ($serving->where('responsibility', GrievanceUnitResponsibility::Head->value)->count() !== 1) {
            $problems[] = 'grievances.errors.unit_needs_one_head';
        }
        if ($serving->contains(fn (GrievanceHandlerUnitMember $m) => ! $m->hasSignature())) {
            $problems[] = 'grievances.errors.unit_signatures_missing';
        }
        $unitId = (string) $designation->organization_unit_id;
        if ($serving->contains(fn (GrievanceHandlerUnitMember $m) => $m->employee === null || ! $this->handlers->isPlacedIn($m->employee, $unitId))) {
            $problems[] = 'grievances.errors.unit_member_moved';
        }

        return $problems;
    }

    /** @return Collection<int, GrievanceHandlerUnitMember> */
    public function servingMembers(GrievanceHandlerUnit $designation): Collection
    {
        return $designation->members()->servingOn(now())->get();
    }

    // ── Internals ────────────────────────────────────────────────────────────

    /** ADR 0030: the system has one Grievance Directorate — the last internal level before the court. */
    private function assertSingleDirectorate(string $kind, ?GrievanceHandlerUnit $self): void
    {
        if ($kind !== 'directorate') {
            return;
        }
        $other = GrievanceHandlerUnit::query()->where('kind', 'directorate')->where('status', 'active')
            ->when($self !== null, fn ($q) => $q->whereKeyNot($self->getKey()))->exists();
        if ($other) {
            throw ValidationException::withMessages(['kind' => __('grievances.errors.only_one_directorate')]);
        }
    }

    private function assertOneHead(GrievanceHandlerUnit $designation, GrievanceUnitResponsibility $responsibility, Carbon $from, ?GrievanceHandlerUnitMember $self): void
    {
        if ($responsibility !== GrievanceUnitResponsibility::Head) {
            return;
        }
        $otherHead = $designation->members()->servingOn($from)->where('responsibility', GrievanceUnitResponsibility::Head->value)
            ->when($self !== null, fn ($q) => $q->whereKeyNot($self->getKey()))->exists();
        if ($otherHead) {
            throw ValidationException::withMessages(['responsibility' => __('grievances.errors.unit_already_has_head')]);
        }
    }

    private function storeSignature(Employee $employee, mixed $upload, ?string $storedPath): string
    {
        if ($upload instanceof UploadedFile) {
            $extension = strtolower($upload->getClientOriginalExtension() ?: ($upload->extension() ?: 'png'));
            $extension = $extension === 'jpeg' ? 'jpg' : $extension;
            $path = 'employee-signatures/'.Str::uuid().'.'.$extension;
            Storage::disk('local')->put($path, (string) file_get_contents($upload->getRealPath()));
            $employee->forceFill(['signature_path' => $path])->save();

            return $path;
        }
        if (is_string($storedPath) && $storedPath !== '') {
            if (! $employee->signature_path) {
                $employee->forceFill(['signature_path' => $storedPath])->save();
            }

            return $storedPath;
        }
        if ($employee->signature_path) {
            return $employee->signature_path;
        }

        throw ValidationException::withMessages(['signature' => __('grievances.errors.committee_member_signature_required')]);
    }

    private function assertManage(User $actor, string $organizationId): void
    {
        $this->access->authorize($actor->can('grievance_committees.manage') && $this->scope->canExercisePermission($actor, 'grievance_committees.manage', $organizationId));
    }

    private function assertManageMembers(User $actor, GrievanceHandlerUnit $designation): void
    {
        $this->access->authorize($actor->can('grievance_committee_members.manage') && $this->scope->canExercisePermission($actor, 'grievance_committee_members.manage', (string) $designation->organization_id));
    }
}

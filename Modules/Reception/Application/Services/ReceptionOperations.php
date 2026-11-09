<?php

declare(strict_types=1);

namespace Modules\Reception\Application\Services;

use App\Actions\Audit\WriteAuditLogAction;
use App\Actions\CodeRules\GenerateCodeAction;
use App\Enums\AuditEventType;
use App\Enums\CodeRuleEntityType;
use App\Models\OrganizationUnit;
use App\Models\Service;
use App\Models\User;
use App\Services\Calendar\WorkCalendarService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Reception\Contracts\Data\ReceptionPermissions;
use Modules\Reception\Domain\Models\ReceptionAppointment;
use Modules\Reception\Domain\Models\ReceptionCounter;
use Modules\Reception\Domain\Models\ReceptionDesk;
use Modules\Reception\Domain\Models\ReceptionQueueSequence;
use Modules\Reception\Domain\Models\ReceptionQueueTicket;
use Modules\Reception\Domain\Models\ReceptionReferral;
use Modules\Reception\Domain\Models\ReceptionVisit;
use Modules\Reception\Domain\Models\ReceptionVisitor;

final readonly class ReceptionOperations
{
    public function __construct(
        private ReceptionAccess $access,
        private WriteAuditLogAction $audit,
        private GenerateCodeAction $codes,
        private WorkCalendarService $calendar,
    ) {}

    public function createDesk(array $data, User $actor): ReceptionDesk
    {
        $this->access->authorize($actor, ReceptionPermissions::CONFIGURE, $data['organization_id']);

        return DB::transaction(function () use ($data, $actor): ReceptionDesk {
            $desk = ReceptionDesk::query()->create([
                ...$data,
                'code' => $this->codes->execute(CodeRuleEntityType::ReceptionDesk, ['organization_id' => $data['organization_id']], $actor),
                'created_by' => $actor->id,
            ]);
            $desk->services()->sync($data['service_ids'] ?? []);
            $this->audit->execute(AuditEventType::ReceptionConfigurationChanged, $actor, $desk, $desk->organization_id, newValues: ['code' => $desk->code]);

            return $desk;
        });
    }

    public function createCounter(array $data, User $actor): ReceptionCounter
    {
        $desk = ReceptionDesk::query()->findOrFail($data['reception_desk_id']);
        $this->access->authorize($actor, ReceptionPermissions::CONFIGURE, $desk->organization_id);
        $counter = ReceptionCounter::query()->create($data);
        $this->audit->execute(AuditEventType::ReceptionConfigurationChanged, $actor, $counter, $desk->organization_id, newValues: ['counter' => $counter->code]);

        return $counter;
    }

    public function updateCounter(ReceptionCounter $counter, array $data, User $actor): ReceptionCounter
    {
        $counter->loadMissing('desk');
        $permission = $actor->can(ReceptionPermissions::CONFIGURE) ? ReceptionPermissions::CONFIGURE : ReceptionPermissions::SUPERVISE;
        $this->access->authorize($actor, $permission, $counter->desk->organization_id);
        $counter->update($data);
        $this->audit->execute(AuditEventType::ReceptionConfigurationChanged, $actor, $counter, $counter->desk->organization_id, newValues: $data);

        return $counter->refresh();
    }

    public function assignStaff(array $data, User $actor): void
    {
        $desk = ReceptionDesk::query()->findOrFail($data['reception_desk_id']);
        $this->access->authorize($actor, ReceptionPermissions::CONFIGURE, $desk->organization_id);
        $desk->staff()->syncWithoutDetaching([$data['user_id'] => ['id' => (string) Str::uuid7(), 'role' => $data['role'], 'is_active' => $data['is_active'] ?? true]]);
        $this->audit->execute(AuditEventType::ReceptionConfigurationChanged, $actor, $desk, $desk->organization_id, newValues: ['staff_user_id' => $data['user_id'], 'role' => $data['role']]);
    }

    public function registerVisit(array $data, User $actor): ReceptionVisit
    {
        $desk = ReceptionDesk::query()->where('is_active', true)->findOrFail($data['reception_desk_id']);
        $this->access->authorize($actor, ReceptionPermissions::OPERATE, $desk->organization_id);
        if ($desk->organization_id !== $data['organization_id']) {
            throw ValidationException::withMessages(['organization_id' => __('reception.validation.desk_organization')]);
        }
        $this->validateOrganizationReferences($data, $desk->organization_id);

        $appointment = null;
        if (isset($data['appointment_id'])) {
            $appointment = ReceptionAppointment::query()->whereKey($data['appointment_id'])
                ->where('organization_id', $desk->organization_id)
                ->where('reception_desk_id', $desk->id)
                ->whereIn('status', ['scheduled', 'rescheduled'])
                ->first();
            if ($appointment === null) {
                throw ValidationException::withMessages(['appointment_id' => __('reception.validation.appointment_scope')]);
            }
        }

        return DB::transaction(function () use ($data, $actor, $desk, $appointment): ReceptionVisit {
            $phone = $this->normalizePhone($data['phone'] ?? null);
            $hash = $phone === null ? null : hash_hmac('sha256', $phone, (string) config('app.key'));
            $visitor = $appointment?->visitor;
            $visitor ??= $hash === null ? null : ReceptionVisitor::query()->where('phone_hash', $hash)->first();
            $visitor ??= ReceptionVisitor::query()->create([
                'name' => $data['visitor_name'] ?? null,
                'phone' => $phone,
                'phone_hash' => $hash,
                'category' => $data['visitor_category'],
                'employee_id' => $data['employee_id'] ?? null,
            ]);

            $visit = ReceptionVisit::query()->create([
                'reference' => $this->codes->execute(CodeRuleEntityType::ReceptionVisit, ['organization_id' => $desk->organization_id], $actor, null, 'reference'),
                'visitor_id' => $visitor->id,
                'organization_id' => $desk->organization_id,
                'organization_unit_id' => $data['organization_unit_id'] ?? null,
                'service_id' => $data['service_id'] ?? null,
                'reception_desk_id' => $desk->id,
                'appointment_id' => $data['appointment_id'] ?? null,
                'reception_officer_id' => $actor->id,
                'purpose' => $data['purpose'] ?? null,
                'status' => 'checked_in',
                'checked_in_at' => now(),
            ]);

            if ($visit->appointment_id !== null) {
                ReceptionAppointment::query()->whereKey($visit->appointment_id)->update(['status' => 'checked_in', 'checked_in_at' => now()]);
            }
            $this->audit->execute(AuditEventType::ReceptionVisitorCheckedIn, $actor, $visit, $visit->organization_id, newValues: ['reference' => $visit->reference]);

            return $visit;
        });
    }

    public function issueTicket(ReceptionVisit $visit, array $data, User $actor): ReceptionQueueTicket
    {
        $this->access->authorize($actor, ReceptionPermissions::OPERATE, $visit->organization_id);
        $this->validateCounter($data['reception_counter_id'] ?? null, $visit->reception_desk_id);
        if ($visit->queueTicket()->exists()) {
            throw ValidationException::withMessages(['visit_id' => __('reception.validation.active_ticket')]);
        }

        return DB::transaction(function () use ($visit, $data, $actor): ReceptionQueueTicket {
            $date = today()->toDateString();
            ReceptionQueueSequence::query()->firstOrCreate(['reception_desk_id' => $visit->reception_desk_id, 'queue_date' => $date], ['last_number' => 0]);
            $sequence = ReceptionQueueSequence::query()->where('reception_desk_id', $visit->reception_desk_id)->whereDate('queue_date', $date)->lockForUpdate()->firstOrFail();
            $number = $sequence->last_number + 1;
            $sequence->update(['last_number' => $number]);
            $ticket = ReceptionQueueTicket::query()->create([
                'visit_id' => $visit->id,
                'reception_desk_id' => $visit->reception_desk_id,
                'reception_counter_id' => $data['reception_counter_id'] ?? null,
                'queue_date' => $date,
                'queue_number' => $number,
                'display_number' => str_pad((string) $number, 3, '0', STR_PAD_LEFT),
                'priority' => $data['priority'] ?? 'normal',
                'status' => 'waiting',
                'issued_at' => now(),
            ]);
            $visit->update(['status' => 'waiting']);
            $this->audit->execute(AuditEventType::ReceptionQueueChanged, $actor, $ticket, $visit->organization_id, newValues: ['status' => 'waiting', 'number' => $number]);

            return $ticket;
        });
    }

    public function transitionTicket(ReceptionQueueTicket $ticket, string $transition, ?string $counterId, User $actor): ReceptionQueueTicket
    {
        $ticket->loadMissing('visit');
        $this->access->authorize($actor, ReceptionPermissions::OPERATE, $ticket->visit->organization_id);
        $this->validateCounter($counterId, $ticket->reception_desk_id);
        $allowed = [
            'waiting' => ['call' => 'called', 'cancel' => 'cancelled'],
            'called' => ['recall' => 'called', 'start' => 'in_service', 'no_show' => 'no_show', 'transfer' => 'waiting'],
            'in_service' => ['complete' => 'completed', 'transfer' => 'waiting'],
        ];
        $next = $allowed[$ticket->status][$transition] ?? null;
        if ($next === null) {
            throw ValidationException::withMessages(['transition' => __('reception.validation.invalid_transition')]);
        }

        return DB::transaction(function () use ($ticket, $transition, $counterId, $next, $actor): ReceptionQueueTicket {
            $changes = ['status' => $next];
            if ($counterId !== null) {
                $changes['reception_counter_id'] = $counterId;
            }
            if ($transition === 'call') {
                $changes['called_at'] = now();
            }
            if ($transition === 'recall') {
                $changes['recall_count'] = $ticket->recall_count + 1;
            }
            if ($transition === 'start') {
                $changes['service_started_at'] = now();
            }
            if (in_array($transition, ['complete', 'cancel', 'no_show'], true)) {
                $changes['completed_at'] = now();
            }
            $ticket->update($changes);

            $visitChanges = ['status' => $next];
            if ($transition === 'start') {
                $visitChanges['service_started_at'] = now();
            }
            if (in_array($transition, ['complete', 'cancel', 'no_show'], true)) {
                $visitChanges['completed_at'] = now();
            }
            $ticket->visit->update($visitChanges);
            $this->audit->execute(AuditEventType::ReceptionQueueChanged, $actor, $ticket, $ticket->visit->organization_id, newValues: ['transition' => $transition, 'status' => $next]);

            return $ticket->refresh();
        });
    }

    public function createAppointment(array $data, User $actor): ReceptionAppointment
    {
        $desk = ReceptionDesk::query()->findOrFail($data['reception_desk_id']);
        $this->access->authorize($actor, ReceptionPermissions::APPOINTMENTS, $desk->organization_id);
        $this->validateOrganizationReferences($data, $desk->organization_id);
        if (! ReceptionVisitor::query()->whereKey($data['visitor_id'])->whereHas('visits', fn ($query) => $query->where('organization_id', $desk->organization_id))->exists()) {
            throw ValidationException::withMessages(['visitor_id' => __('reception.validation.visitor_scope')]);
        }
        $scheduled = Carbon::parse($data['scheduled_at']);
        if (! $this->calendar->isWorkingDay($scheduled)) {
            throw ValidationException::withMessages(['scheduled_at' => __('reception.validation.non_working_day')]);
        }
        $appointment = ReceptionAppointment::query()->create([
            ...$data, 'reference' => $this->codes->execute(CodeRuleEntityType::ReceptionAppointment, ['organization_id' => $desk->organization_id], $actor, null, 'reference'),
            'organization_id' => $desk->organization_id, 'created_by' => $actor->id,
        ]);
        $this->audit->execute(AuditEventType::ReceptionAppointmentChanged, $actor, $appointment, $desk->organization_id, newValues: ['status' => $appointment->status]);

        return $appointment;
    }

    public function updateAppointment(ReceptionAppointment $appointment, array $data, User $actor): ReceptionAppointment
    {
        $appointment->loadMissing('desk');
        $this->access->authorize($actor, ReceptionPermissions::APPOINTMENTS, $appointment->desk->organization_id);
        if (($data['status'] ?? null) === 'rescheduled') {
            $scheduled = Carbon::parse($data['scheduled_at']);
            if (! $this->calendar->isWorkingDay($scheduled)) {
                throw ValidationException::withMessages(['scheduled_at' => __('reception.validation.non_working_day')]);
            }
        }
        $appointment->update($data);
        $this->audit->execute(AuditEventType::ReceptionAppointmentChanged, $actor, $appointment, $appointment->desk->organization_id, newValues: $data);

        return $appointment->refresh();
    }

    public function refer(ReceptionVisit $visit, array $data, User $actor): ReceptionReferral
    {
        $this->access->authorize($actor, ReceptionPermissions::OPERATE, $visit->organization_id);
        $this->access->authorize($actor, ReceptionPermissions::OPERATE, $data['to_organization_id']);
        $this->validateOrganizationReferences($data, $data['to_organization_id']);
        $referral = ReceptionReferral::query()->create([
            ...$data, 'visit_id' => $visit->id, 'from_organization_id' => $visit->organization_id,
            'referred_by' => $actor->id, 'referred_at' => now(), 'status' => 'pending',
        ]);
        $visit->update(['status' => 'referred', 'outcome' => 'referred']);
        $this->audit->execute(AuditEventType::ReceptionReferralChanged, $actor, $referral, $visit->organization_id, newValues: ['to_organization_id' => $referral->to_organization_id]);

        return $referral;
    }

    public function acknowledgeReferral(ReceptionReferral $referral, ?string $outcome, User $actor): ReceptionReferral
    {
        $this->access->authorize($actor, ReceptionPermissions::OPERATE, $referral->to_organization_id);
        $referral->update(['status' => 'acknowledged', 'received_by' => $actor->id, 'acknowledged_at' => now(), 'outcome' => $outcome]);
        $this->audit->execute(AuditEventType::ReceptionReferralChanged, $actor, $referral, $referral->to_organization_id, newValues: ['status' => 'acknowledged']);

        return $referral->refresh();
    }

    private function normalizePhone(?string $phone): ?string
    {
        $normalized = $phone === null ? '' : preg_replace('/[^0-9+]/', '', trim($phone));

        return $normalized === '' ? null : $normalized;
    }

    private function validateCounter(?string $counterId, string $deskId): void
    {
        if ($counterId !== null && ! ReceptionCounter::query()->whereKey($counterId)->where('reception_desk_id', $deskId)->exists()) {
            throw ValidationException::withMessages(['reception_counter_id' => __('reception.validation.counter_scope')]);
        }
    }

    private function validateOrganizationReferences(array $data, string $organizationId): void
    {
        if (isset($data['organization_unit_id']) && ! OrganizationUnit::query()->whereKey($data['organization_unit_id'])->where('organization_id', $organizationId)->exists()) {
            throw ValidationException::withMessages(['organization_unit_id' => __('reception.validation.unit_scope')]);
        }
        if (isset($data['to_organization_unit_id']) && ! OrganizationUnit::query()->whereKey($data['to_organization_unit_id'])->where('organization_id', $organizationId)->exists()) {
            throw ValidationException::withMessages(['to_organization_unit_id' => __('reception.validation.unit_scope')]);
        }
        if (isset($data['service_id']) && ! Service::query()->whereKey($data['service_id'])->where('organization_id', $organizationId)->exists()) {
            throw ValidationException::withMessages(['service_id' => __('reception.validation.service_scope')]);
        }
    }
}

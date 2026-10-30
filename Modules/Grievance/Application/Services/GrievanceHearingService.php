<?php

declare(strict_types=1);

namespace App\Services\Grievances;

use App\Enums\AuditEventType;
use App\Enums\Grievance\GrievanceCommitteeRole;
use App\Enums\Grievance\GrievanceHearingMode;
use App\Enums\Grievance\GrievanceHearingStatus;
use App\Enums\Grievance\GrievanceMinutesStatus;
use App\Enums\Grievance\GrievanceParticipantRole;
use App\Enums\Grievance\GrievanceStageStatus;
use App\Models\Grievance;
use App\Models\GrievanceHearing;
use App\Models\GrievanceHearingParticipant;
use App\Models\GrievanceMinutes;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Hearings, participants and minutes (docs/grievance-management.md §8.5).
 * Draft minutes are editable by the writer/lead; confirmed minutes are
 * immutable and change only through a new version that supersedes them.
 */
final class GrievanceHearingService
{
    public function __construct(
        private readonly GrievanceCaseAccessService $access,
        private readonly GrievanceCaseService $cases,
        private readonly GrievanceAudit $audit,
        private readonly GrievanceTimeline $timeline,
        private readonly GrievanceNotifier $notifier,
    ) {}

    /**
     * @param  array{scheduled_at: string, duration_minutes?: int|null, location?: string|null, mode: string, meeting_link?: string|null, agenda?: string|null, participants?: list<array{role: string, employee_id?: string|null, name?: string|null, affiliation?: string|null, contact?: string|null}>}  $data
     */
    public function schedule(Grievance $grievance, User $actor, array $data): GrievanceHearing
    {
        $this->access->authorize($this->access->canManageHearings($actor, $grievance));

        return DB::transaction(function () use ($grievance, $actor, $data): GrievanceHearing {
            $grievance = $this->cases->lock($grievance);
            $stage = $this->cases->lockedCurrentStage($grievance);

            $chair = $stage->members()->where('is_active', true)->whereNull('recused_at')->where('role', GrievanceCommitteeRole::Chairperson->value)->value('employee_id');
            $hearing = GrievanceHearing::query()->create([
                'grievance_id' => $grievance->getKey(),
                'case_stage_id' => $stage->getKey(),
                'scheduled_at' => $data['scheduled_at'],
                'duration_minutes' => $data['duration_minutes'] ?? null,
                'location' => $data['location'] ?? null,
                'mode' => GrievanceHearingMode::from($data['mode']),
                'meeting_link' => $data['meeting_link'] ?? null,
                'status' => GrievanceHearingStatus::Scheduled,
                'chairperson_employee_id' => $chair,
                'agenda' => $data['agenda'] ?? null,
                'created_by' => $actor->getKey(),
            ]);

            // The complainant and the active panel are always invited.
            $this->addParticipant($hearing, ['role' => GrievanceParticipantRole::Complainant->value, 'employee_id' => $grievance->employee_id]);
            foreach ($stage->members()->where('is_active', true)->whereNull('recused_at')->get() as $seat) {
                $this->addParticipant($hearing, ['role' => GrievanceParticipantRole::CommitteeMember->value, 'employee_id' => $seat->employee_id]);
            }
            foreach ($data['participants'] ?? [] as $participant) {
                $this->addParticipant($hearing, $participant);
            }

            $this->cases->setWorkingStatus($grievance->setRelation('currentStage', $stage), GrievanceStageStatus::HearingScheduled);
            $this->audit->record(AuditEventType::GrievanceHearingScheduled, $actor, $hearing, ['scheduled_at' => $hearing->scheduled_at?->toIso8601String(), 'mode' => $hearing->mode->value]);
            $this->timeline->record($grievance, 'hearing_scheduled', $actor, ['hearing_id' => $hearing->getKey(), 'scheduled_at' => $hearing->scheduled_at?->toIso8601String(), 'mode' => $hearing->mode->value], $stage->getKey());
            $this->notifier->toComplainant($grievance, 'hearing_scheduled');

            return $hearing;
        });
    }

    /** @param  array{role: string, employee_id?: string|null, name?: string|null, affiliation?: string|null, contact?: string|null}  $data */
    public function addParticipant(GrievanceHearing $hearing, array $data): GrievanceHearingParticipant
    {
        $role = GrievanceParticipantRole::from($data['role']);
        if (($data['employee_id'] ?? null) === null && trim((string) ($data['name'] ?? '')) === '') {
            throw ValidationException::withMessages(['participants' => __('grievances.errors.participant_needs_identity')]);
        }
        if (! empty($data['employee_id']) && $hearing->participants()->where('employee_id', $data['employee_id'])->exists()) {
            return $hearing->participants()->where('employee_id', $data['employee_id'])->first();
        }

        return $hearing->participants()->create([
            'role' => $role,
            'employee_id' => $data['employee_id'] ?? null,
            'name' => $data['name'] ?? null,
            'affiliation' => $data['affiliation'] ?? null,
            'contact' => $data['contact'] ?? null,
            'attendance' => 'invited',
        ]);
    }

    /** @param  array{status: string, scheduled_at?: string|null, location?: string|null, notes?: string|null, cancellation_reason?: string|null, attendance?: array<string, string>}  $data */
    public function update(GrievanceHearing $hearing, User $actor, array $data): GrievanceHearing
    {
        $grievance = $hearing->grievance;
        $this->access->authorize($grievance !== null && $this->access->canManageHearings($actor, $grievance));
        $status = GrievanceHearingStatus::from($data['status']);
        if ($status === GrievanceHearingStatus::Cancelled && trim((string) ($data['cancellation_reason'] ?? '')) === '') {
            throw ValidationException::withMessages(['cancellation_reason' => __('grievances.errors.reason_required')]);
        }

        DB::transaction(function () use ($hearing, $data, $status): void {
            $hearing->forceFill([
                'status' => $status,
                'scheduled_at' => $data['scheduled_at'] ?? $hearing->scheduled_at,
                'location' => $data['location'] ?? $hearing->location,
                'notes' => $data['notes'] ?? $hearing->notes,
                'cancellation_reason' => $data['cancellation_reason'] ?? $hearing->cancellation_reason,
                'held_at' => $status === GrievanceHearingStatus::Held ? ($hearing->held_at ?? now()) : $hearing->held_at,
            ])->save();
            foreach ($data['attendance'] ?? [] as $participantId => $attendance) {
                if (in_array($attendance, ['invited', 'attended', 'absent', 'excused'], true)) {
                    $hearing->participants()->whereKey($participantId)->update(['attendance' => $attendance]);
                }
            }
        });

        if (in_array($status, [GrievanceHearingStatus::Held, GrievanceHearingStatus::Cancelled], true)
            && ! $grievance->hearings()->where('status', GrievanceHearingStatus::Scheduled->value)->exists()) {
            $grievance->load('currentStage');
            $this->cases->setWorkingStatus($grievance, GrievanceStageStatus::UnderReview);
        }
        $this->audit->record(AuditEventType::GrievanceHearingUpdated, $actor, $hearing, ['status' => $status->value]);
        if ($status === GrievanceHearingStatus::Cancelled) {
            $this->timeline->record($grievance, 'hearing_cancelled', $actor, ['hearing_id' => $hearing->getKey()], $hearing->case_stage_id);
            $this->notifier->toComplainant($grievance, 'hearing_cancelled');
        }

        return $hearing;
    }

    // ── Minutes ──────────────────────────────────────────────────────────────

    /** @param  array{hearing_id?: string|null, meeting_date: string, summary: string, discussion?: string|null, resolutions?: string|null, attendees?: list<string>|null}  $data */
    public function draftMinutes(Grievance $grievance, User $actor, array $data): GrievanceMinutes
    {
        $this->access->authorize($this->access->canManageHearings($actor, $grievance));
        if (! empty($data['hearing_id']) && ! $grievance->hearings()->whereKey($data['hearing_id'])->exists()) {
            throw ValidationException::withMessages(['hearing_id' => __('grievances.errors.stale')]);
        }

        $minutes = GrievanceMinutes::query()->create([
            'grievance_id' => $grievance->getKey(),
            'case_stage_id' => $grievance->current_stage_id,
            'hearing_id' => $data['hearing_id'] ?? null,
            'meeting_date' => $data['meeting_date'],
            'summary' => $data['summary'],
            'discussion' => $data['discussion'] ?? null,
            'resolutions' => $data['resolutions'] ?? null,
            'attendees' => $data['attendees'] ?? null,
            'status' => GrievanceMinutesStatus::Draft,
            'version_no' => 1,
            'prepared_by' => $actor->getKey(),
            'prepared_by_employee_id' => $actor->employee_id,
        ]);
        $this->audit->record(AuditEventType::GrievanceMinutesDrafted, $actor, $minutes, ['minutes_id' => $minutes->getKey()]);

        return $minutes;
    }

    /** @param  array<string, mixed>  $data */
    public function updateMinutes(GrievanceMinutes $minutes, User $actor, array $data): GrievanceMinutes
    {
        $grievance = Grievance::query()->find($minutes->grievance_id);
        $this->access->authorize($grievance !== null && $this->access->canManageHearings($actor, $grievance));
        if ($minutes->status !== GrievanceMinutesStatus::Draft) {
            throw ValidationException::withMessages(['minutes' => __('grievances.errors.minutes_confirmed')]);
        }
        $minutes->fill(array_intersect_key($data, array_flip(['meeting_date', 'summary', 'discussion', 'resolutions', 'attendees'])))->save();
        $this->audit->record(AuditEventType::GrievanceMinutesDrafted, $actor, $minutes, ['minutes_id' => $minutes->getKey(), 'updated' => true]);

        return $minutes;
    }

    /** Chairperson (or unit lead) confirms; from then on the minutes are immutable. */
    public function confirmMinutes(GrievanceMinutes $minutes, User $actor): GrievanceMinutes
    {
        $grievance = Grievance::query()->find($minutes->grievance_id);
        $this->access->authorize($grievance !== null && $this->access->canLead($actor, $grievance, 'grievance_hearings.manage'));

        return DB::transaction(function () use ($minutes, $actor, $grievance): GrievanceMinutes {
            $minutes = GrievanceMinutes::query()->whereKey($minutes->getKey())->lockForUpdate()->firstOrFail();
            if ($minutes->status !== GrievanceMinutesStatus::Draft) {
                throw ValidationException::withMessages(['minutes' => __('grievances.errors.stale')]);
            }
            $minutes->forceFill(['status' => GrievanceMinutesStatus::Confirmed, 'confirmed_by' => $actor->getKey(), 'confirmed_at' => now()])->save();
            if ($minutes->supersedes_minutes_id) {
                GrievanceMinutes::query()->whereKey($minutes->supersedes_minutes_id)->update(['status' => GrievanceMinutesStatus::Superseded->value]);
            }
            $this->audit->record(AuditEventType::GrievanceMinutesConfirmed, $actor, $minutes, ['minutes_id' => $minutes->getKey(), 'version_no' => $minutes->version_no]);
            $this->timeline->record($grievance, 'minutes_confirmed', $actor, ['version_no' => $minutes->version_no], $minutes->case_stage_id);

            return $minutes;
        });
    }

    /** Controlled amendment: a new draft version that will supersede the confirmed one. */
    public function amendMinutes(GrievanceMinutes $minutes, User $actor, string $reason): GrievanceMinutes
    {
        $grievance = Grievance::query()->find($minutes->grievance_id);
        $this->access->authorize($grievance !== null && $this->access->canManageHearings($actor, $grievance));
        if ($minutes->status !== GrievanceMinutesStatus::Confirmed || trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => __('grievances.errors.reason_required')]);
        }

        $new = $minutes->replicate(['status', 'confirmed_by', 'confirmed_at', 'version_no', 'supersedes_minutes_id', 'amendment_reason']);
        $new->forceFill([
            'status' => GrievanceMinutesStatus::Draft,
            'version_no' => $minutes->version_no + 1,
            'supersedes_minutes_id' => $minutes->getKey(),
            'amendment_reason' => $reason,
            'prepared_by' => $actor->getKey(),
            'prepared_by_employee_id' => $actor->employee_id,
        ])->save();
        $this->audit->record(AuditEventType::GrievanceMinutesAmended, $actor, $new, ['supersedes' => $minutes->getKey()], null, $reason);

        return $new;
    }
}

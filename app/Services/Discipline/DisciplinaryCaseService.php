<?php

declare(strict_types=1);

namespace App\Services\Discipline;

use App\Actions\Audit\WriteAuditLogAction;
use App\Actions\CodeRules\GenerateCodeAction;
use App\Enums\AssignmentStatus;
use App\Enums\AuditEventType;
use App\Enums\CodeRuleEntityType;
use App\Enums\CommitteeType;
use App\Enums\Discipline\DisciplinaryCaseRole;
use App\Enums\Discipline\DisciplinaryCaseStatus;
use App\Enums\Discipline\DisciplinaryEvidenceSide;
use App\Enums\Discipline\DisciplinaryFinding;
use App\Enums\Discipline\DisciplinaryHeadAction;
use App\Enums\Discipline\DisciplinaryObjectionGround;
use App\Enums\Discipline\DisciplinaryPenalty;
use App\Enums\Discipline\DisciplinaryPlea;
use App\Enums\Discipline\DisciplinarySeverity;
use App\Enums\Discipline\DisciplinaryTrack;
use App\Models\DisciplinaryCase;
use App\Models\DisciplinaryCaseMember;
use App\Models\DisciplinaryCharge;
use App\Models\DisciplinaryDecision;
use App\Models\DisciplinaryEvidence;
use App\Models\DisciplinaryRecommendation;
use App\Models\DisciplinaryResponse;
use App\Models\DisciplinaryWitness;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\GrievanceCommittee;
use App\Models\GrievanceCommitteeMember;
use App\Models\User;
use App\Services\Legal\StatutoryCommitteeRules;
use App\Services\Legal\StatutoryDeadlineService;
use App\Support\Legal\Regulation201;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The only writer of disciplinary case state. Each step enforces the
 * Regulation 201/2018 article named beside it, records an immutable status
 * history row and an audit event, and snapshots statutory deadlines through
 * StatutoryDeadlineService.
 *
 * Deliberately absent: automatic charges from complaints, automatic penalties,
 * automatic suspension, and any machine-generated finding or penalty. A human
 * holding the right permission decides every outcome.
 */
final class DisciplinaryCaseService
{
    public const DISK = 'local';

    public function __construct(
        private readonly DisciplinaryCaseAccess $access,
        private readonly StatutoryDeadlineService $deadlines,
        private readonly StatutoryCommitteeRules $committees,
        private readonly GenerateCodeAction $codes,
        private readonly WriteAuditLogAction $audit,
    ) {}

    // ── Opening and charging ─────────────────────────────────────────────────

    /**
     * Open a draft case against $accused. Placement is the accused's current
     * assignment, snapshotted so a later transfer never rewrites the case.
     *
     * @param  array{track: string, severity: string, summary?: string|null, source_type?: string|null, source_id?: string|null}  $data
     */
    public function open(User $actor, Employee $accused, array $data): DisciplinaryCase
    {
        $assignment = $this->currentAssignment($accused);
        if ($assignment === null) {
            $this->fail('accused', 'no_current_assignment');
        }
        $this->access->authorize($this->access->canCreateIn($actor, $assignment->organization_id) && $actor->employee_id !== $accused->getKey());

        $track = DisciplinaryTrack::from($data['track']);
        $severity = DisciplinarySeverity::from($data['severity']);
        // Art. 13(1) expedited inquiry is for minor violations; Art. 14 formal for serious ones.
        if ($track === DisciplinaryTrack::Expedited && $severity !== DisciplinarySeverity::Minor) {
            $this->fail('track', 'expedited_minor_only');
        }

        $sourceType = $data['source_type'] ?? null;
        $sourceId = $data['source_id'] ?? null;
        if ($sourceType !== null && $sourceId !== null) {
            // Art. 17(5): a charge discontinued by the institution cannot be brought again on the same matter.
            $discontinued = DisciplinaryCase::query()->where('source_type', $sourceType)->where('source_id', $sourceId)
                ->where('status', DisciplinaryCaseStatus::Discontinued->value)->exists();
            if ($discontinued) {
                $this->fail('source_id', 'discontinued_same_matter');
            }
        }

        return DB::transaction(function () use ($actor, $accused, $assignment, $track, $severity, $data, $sourceType, $sourceId): DisciplinaryCase {
            $case = new DisciplinaryCase;
            $case->forceFill([
                'accused_employee_id' => $accused->getKey(),
                'accused_assignment_id' => $assignment->getKey(),
                'organization_id' => $assignment->organization_id,
                'organization_unit_id' => $assignment->organization_unit_id,
                'position_id' => $assignment->position_id,
                'track' => $track,
                'severity' => $severity,
                'status' => DisciplinaryCaseStatus::Draft,
                'summary' => $this->clean($data['summary'] ?? null),
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'created_by' => $actor->getKey(),
            ])->save();

            $case->forceFill(['case_number' => $this->codes->execute(
                CodeRuleEntityType::DisciplinaryCase,
                ['organization_id' => $case->organization_id],
                $actor,
                null,
                'case_number',
                $case->getKey(),
            )])->save();

            $this->transition($case, null, DisciplinaryCaseStatus::Draft, $actor, null);
            $this->record(AuditEventType::DisciplinaryCaseCreated, $actor, $case, [
                'case_number' => $case->case_number, 'track' => $track->value, 'severity' => $severity->value,
                'source_type' => $sourceType, 'source_id' => $sourceId,
            ]);

            return $case;
        });
    }

    /**
     * Add one alleged violation with every element Art. 15(1)(b)–(e) requires.
     *
     * @param  array{title: string, particulars: string, occurred_on: string, occurred_place: string, violated_provision: string, evidence_list: string}  $data
     */
    public function addCharge(DisciplinaryCase $case, User $actor, array $data): DisciplinaryCharge
    {
        $this->access->authorize($this->access->canOperate($actor, $case, 'discipline.create'));
        $this->requireStatus($case, [DisciplinaryCaseStatus::Draft]);

        return DB::transaction(function () use ($case, $actor, $data): DisciplinaryCharge {
            $charge = new DisciplinaryCharge;
            $charge->forceFill([
                'case_id' => $case->getKey(),
                'sequence' => (int) $case->charges()->max('sequence') + 1,
                'title' => trim($data['title']),
                'particulars' => trim($data['particulars']),
                'occurred_on' => $data['occurred_on'],
                'occurred_place' => trim($data['occurred_place']),
                'violated_provision' => trim($data['violated_provision']),
                'evidence_list' => trim($data['evidence_list']),
            ])->save();

            $this->record(AuditEventType::DisciplinaryChargeRecorded, $actor, $case, ['charge_id' => $charge->getKey(), 'sequence' => $charge->sequence]);

            return $charge;
        });
    }

    /**
     * Seat the panel. Formal: an active disciplinary committee of the case's
     * institution whose composition satisfies Art. 5. Expedited: one or more
     * appointed investigators (Art. 13(1)). The accused never takes a seat.
     *
     * @param  list<string>  $investigatorEmployeeIds
     */
    public function assignPanel(DisciplinaryCase $case, User $actor, ?string $committeeId, array $investigatorEmployeeIds = []): void
    {
        $this->access->authorize($this->access->canOperate($actor, $case, 'discipline.manage'));
        $this->requireStatus($case, [DisciplinaryCaseStatus::Draft]);

        DB::transaction(function () use ($case, $actor, $committeeId, $investigatorEmployeeIds): void {
            $case->members()->delete();

            if ($case->track === DisciplinaryTrack::Formal) {
                $committee = GrievanceCommittee::query()->whereKey($committeeId)->first();
                if ($committee === null || $committee->committee_type !== CommitteeType::Disciplinary
                    || $committee->organization_id !== $case->organization_id || ! $committee->isActive()) {
                    $this->fail('committee_id', 'committee_invalid');
                }
                if ($this->committees->compositionProblems($committee) !== []) {
                    $this->fail('committee_id', 'committee_composition');
                }

                foreach ($committee->members()->servingOn(now())->get() as $member) {
                    /** @var GrievanceCommitteeMember $member */
                    if ($member->employee_id === $case->accused_employee_id) {
                        continue;
                    }
                    $this->seatMember($case, $member->employee_id, match ($member->roleEnum()->value) {
                        'chairperson' => DisciplinaryCaseRole::Chairperson,
                        'writer' => DisciplinaryCaseRole::Secretary,
                        default => DisciplinaryCaseRole::Member,
                    }, $member->selection_source, $member->getKey());
                }
                $case->forceFill(['committee_id' => $committee->getKey()])->save();
            } else {
                $ids = array_values(array_unique(array_filter($investigatorEmployeeIds)));
                if ($ids === [] || in_array($case->accused_employee_id, $ids, true)) {
                    $this->fail('investigators', 'investigator_required');
                }
                foreach ($ids as $employeeId) {
                    $this->seatMember($case, $employeeId, DisciplinaryCaseRole::Investigator, StatutoryCommitteeRules::APPOINTED, null);
                }
                $case->forceFill(['committee_id' => null])->save();
            }

            $this->record(AuditEventType::DisciplinaryChargeRecorded, $actor, $case, ['panel_assigned' => true, 'committee_id' => $case->committee_id]);
        });
    }

    /**
     * File the signed charge (Art. 15(1): signed by the HR head and sealed).
     * Starts the Art. 27(1) investigation period for a formal case.
     */
    public function fileCharge(DisciplinaryCase $case, User $actor): void
    {
        $this->access->authorize($this->access->canOperate($actor, $case, 'discipline.charge'));
        $this->requireStatus($case, [DisciplinaryCaseStatus::Draft]);
        if (! $case->charges()->exists()) {
            $this->fail('charges', 'charge_required');
        }
        if (! $case->members()->seated()->exists()) {
            $this->fail('panel', 'panel_required');
        }

        DB::transaction(function () use ($case, $actor): void {
            $now = now();
            $case->forceFill([
                'status' => DisciplinaryCaseStatus::Charged,
                'charge_signed_by' => $actor->getKey(),
                'charged_at' => $now,
            ])->save();

            if ($case->track === DisciplinaryTrack::Formal) {
                $this->deadlines->snapshot($case, Regulation201::DISCIPLINE_INVESTIGATION, $now);
            }

            $this->transition($case, DisciplinaryCaseStatus::Draft, DisciplinaryCaseStatus::Charged, $actor, null);
            $this->record(AuditEventType::DisciplinaryCaseCharged, $actor, $case, ['charged_at' => $now->toIso8601String(), 'charges' => $case->charges()->count()]);
        });
    }

    /**
     * Art. 18: the summons, with the charge and evidence attached, must reach
     * the accused at least 10 consecutive working days before the hearing. If
     * it could not be handed over it is posted for 10 working days (Art. 18(3)).
     *
     * @param  array{served_at: string, method: string, hearing_at: string, hearing_location: string}  $data
     */
    public function serveSummons(DisciplinaryCase $case, User $actor, array $data): void
    {
        $this->access->authorize($this->access->canOperate($actor, $case, 'discipline.charge'));
        $this->requireStatus($case, [DisciplinaryCaseStatus::Charged]);
        if ($case->track !== DisciplinaryTrack::Formal) {
            $this->fail('track', 'summons_formal_only');
        }

        $servedAt = Carbon::parse($data['served_at']);
        $hearingAt = Carbon::parse($data['hearing_at']);
        if ($servedAt->isFuture()) {
            $this->fail('served_at', 'not_in_future');
        }
        if (! $this->deadlines->hasMinimumNotice(Regulation201::DISCIPLINE_SUMMONS_NOTICE, $servedAt, $hearingAt)) {
            $this->fail('hearing_at', 'summons_notice_too_short');
        }

        DB::transaction(function () use ($case, $actor, $data, $servedAt, $hearingAt): void {
            $case->forceFill([
                'summons_served_at' => $servedAt,
                'summons_method' => $data['method'],
                'hearing_at' => $hearingAt,
                'hearing_location' => trim($data['hearing_location']),
            ])->save();

            if ($data['method'] === 'posted') {
                $this->deadlines->snapshot($case, Regulation201::DISCIPLINE_SUMMONS_POSTING, $servedAt);
            }

            $this->record(AuditEventType::DisciplinarySummonsServed, $actor, $case, [
                'served_at' => $servedAt->toIso8601String(), 'method' => $data['method'], 'hearing_at' => $hearingAt->toIso8601String(),
            ]);
        });
    }

    // ── Defence ──────────────────────────────────────────────────────────────

    /**
     * Art. 20: the preliminary objection (Art. 19) and the answer are filed
     * together, signed by the accused, admitting or denying every charge.
     * Silence is never an admission: every charge needs an explicit plea.
     *
     * @param  array{pleas: array<string, array{plea: string, statement?: string|null}>, objection_ground?: string|null, objection_text?: string|null, defense_evidence_list?: string|null, acknowledged_name: string}  $data
     */
    public function recordResponse(DisciplinaryCase $case, User $actor, array $data): DisciplinaryResponse
    {
        $this->access->authorize($this->access->canRespondAsAccused($actor, $case) || $this->access->canInvestigate($actor, $case));
        $this->requireStatus($case, [DisciplinaryCaseStatus::Charged, DisciplinaryCaseStatus::UnderInvestigation]);
        if ($case->report_submitted_at !== null) {
            $this->fail('response', 'record_closed');
        }

        $charges = $case->charges()->get()->keyBy('id');
        if (array_diff($charges->keys()->all(), array_keys($data['pleas'])) !== [] || array_diff(array_keys($data['pleas']), $charges->keys()->all()) !== []) {
            $this->fail('pleas', 'plea_every_charge');
        }
        $ground = isset($data['objection_ground']) ? DisciplinaryObjectionGround::from($data['objection_ground']) : null;

        return DB::transaction(function () use ($case, $actor, $data, $charges, $ground): DisciplinaryResponse {
            foreach ($data['pleas'] as $chargeId => $plea) {
                $charges[$chargeId]->forceFill([
                    'plea' => DisciplinaryPlea::from($plea['plea']),
                    'plea_statement' => $this->clean($plea['statement'] ?? null),
                ])->save();
            }

            $response = new DisciplinaryResponse;
            $response->forceFill([
                'case_id' => $case->getKey(),
                'version' => (int) $case->responses()->max('version') + 1,
                'objection_ground' => $ground,
                'objection_text' => $ground === null ? null : $this->clean($data['objection_text'] ?? null),
                'defense_evidence_list' => $this->clean($data['defense_evidence_list'] ?? null),
                'acknowledged_name' => trim($data['acknowledged_name']),
                'recorded_by' => $actor->getKey(),
                'received_at' => now(),
            ])->save();

            $this->moveToInvestigation($case, $actor);
            $this->record(AuditEventType::DisciplinaryResponseRecorded, $actor, $case, [
                'version' => $response->version, 'objection_ground' => $ground?->value, 'by_accused' => $this->access->isAccused($actor, $case),
            ]);

            return $response;
        });
    }

    /**
     * Art. 18(4) / 17(2): the accused did not appear after a proper summons
     * (or left service voluntarily); the case continues in their absence.
     */
    public function proceedInAbsentia(DisciplinaryCase $case, User $actor, string $reason): void
    {
        $this->access->authorize($this->access->canInvestigate($actor, $case));
        $this->requireStatus($case, [DisciplinaryCaseStatus::Charged, DisciplinaryCaseStatus::UnderInvestigation]);
        if ($case->track === DisciplinaryTrack::Formal) {
            $hearingPassed = $case->hearing_at !== null && $case->hearing_at->isPast();
            $postingOpen = $case->deadlines()->where('rule_key', Regulation201::DISCIPLINE_SUMMONS_POSTING)
                ->whereNull('superseded_at')->where('due_at', '>', now())->exists();
            if (! $hearingPassed || $postingOpen) {
                $this->fail('in_absentia', 'absentia_not_yet');
            }
        }

        DB::transaction(function () use ($case, $actor, $reason): void {
            $case->forceFill(['in_absentia' => true])->save();
            $this->moveToInvestigation($case, $actor, $reason);
        });
    }

    /** Art. 19(2): the committee accepts (recommend closure) or rejects (examine the merits) the objection. */
    public function decideObjection(DisciplinaryCase $case, User $actor, bool $accepted, ?string $notes): void
    {
        $this->access->authorize($this->access->canInvestigate($actor, $case));
        $response = $case->latestResponse();
        if ($response === null || $response->objection_ground === null || $response->objection_decision !== null) {
            $this->fail('objection', 'no_open_objection');
        }

        $response->forceFill([
            'objection_decision' => $accepted ? 'accepted' : 'rejected',
            'objection_decision_notes' => $this->clean($notes),
            'objection_decided_by' => $actor->getKey(),
            'objection_decided_at' => now(),
        ])->save();

        $this->record(AuditEventType::DisciplinaryObjectionDecided, $actor, $case, ['accepted' => $accepted, 'ground' => $response->objection_ground->value]);
    }

    // ── Evidence, witnesses, recusal, final statement ───────────────────────

    /** @param  array{side: string, title: string, description?: string|null, held_by?: string|null, charge_id?: string|null}  $data */
    public function addEvidence(DisciplinaryCase $case, User $actor, array $data, ?UploadedFile $file): DisciplinaryEvidence
    {
        $asAccused = $this->access->canRespondAsAccused($actor, $case);
        $this->access->authorize($asAccused || $this->access->canInvestigate($actor, $case));
        $this->requireStatus($case, [DisciplinaryCaseStatus::Draft, DisciplinaryCaseStatus::Charged, DisciplinaryCaseStatus::UnderInvestigation]);

        $side = $asAccused ? DisciplinaryEvidenceSide::Accused : DisciplinaryEvidenceSide::from($data['side']);
        $chargeId = $data['charge_id'] ?? null;
        if ($chargeId !== null && ! $case->charges()->whereKey($chargeId)->exists()) {
            $this->fail('charge_id', 'charge_not_in_case');
        }

        $path = $file?->storeAs('discipline/'.$case->getKey().'/evidence', Str::uuid7().'.'.strtolower($file->getClientOriginalExtension()), self::DISK);
        if ($file !== null && $path === false) {
            throw new \RuntimeException('Evidence file could not be stored.');
        }

        try {
            return DB::transaction(function () use ($case, $actor, $data, $file, $path, $side, $chargeId): DisciplinaryEvidence {
                $evidence = new DisciplinaryEvidence;
                $evidence->forceFill([
                    'case_id' => $case->getKey(),
                    'charge_id' => $chargeId,
                    'side' => $side,
                    'title' => trim($data['title']),
                    'description' => $this->clean($data['description'] ?? null),
                    'held_by' => $this->clean($data['held_by'] ?? null),
                    'disk' => $file ? self::DISK : null,
                    'path' => $path ?: null,
                    'original_name' => $file ? Str::limit($file->getClientOriginalName(), 200, '') : null,
                    'mime_type' => $file?->getMimeType(),
                    'size_bytes' => $file?->getSize(),
                    'sha256' => $file ? hash_file('sha256', $file->getRealPath()) : null,
                    'added_by' => $actor->getKey(),
                ])->save();

                $this->record(AuditEventType::DisciplinaryEvidenceAdded, $actor, $case, [
                    'evidence_id' => $evidence->getKey(), 'side' => $side->value, 'has_file' => $file !== null, 'sha256' => $evidence->sha256,
                ]);

                return $evidence;
            });
        } catch (\Throwable $e) {
            if (is_string($path)) {
                Storage::disk(self::DISK)->delete($path);
            }
            throw $e;
        }
    }

    /** Verified, audited private download. Returns [absolute path, download name, mime]. */
    public function evidenceDownload(DisciplinaryCase $case, DisciplinaryEvidence $evidence, User $actor): array
    {
        $this->access->authorize($evidence->case_id === $case->getKey() && $this->access->canView($actor, $case));
        if ($evidence->path === null || ! Storage::disk((string) $evidence->disk)->exists($evidence->path)) {
            abort(404);
        }
        // Streamed through the disk so it also works on shared object storage.
        if (! \App\Support\Storage\StoredFileChecksum::matches((string) $evidence->disk, $evidence->path, $evidence->sha256)) {
            abort(409, __('discipline.errors.integrity'));
        }

        $this->record(AuditEventType::DisciplinaryEvidenceDownloaded, $actor, $case, ['evidence_id' => $evidence->getKey()]);

        return [(string) $evidence->disk, $evidence->path, (string) ($evidence->original_name ?? 'evidence'), (string) ($evidence->mime_type ?? 'application/octet-stream')];
    }

    /**
     * Art. 23–24 witness record. Particulars of Art. 24(4) are required;
     * written testimony only where the committee allows it (Art. 24(1)).
     *
     * @param  array<string, mixed>  $data
     */
    public function recordWitness(DisciplinaryCase $case, User $actor, array $data): DisciplinaryWitness
    {
        $this->access->authorize($this->access->canInvestigate($actor, $case));
        $this->requireStatus($case, [DisciplinaryCaseStatus::UnderInvestigation]);

        $witness = new DisciplinaryWitness;
        $witness->forceFill([
            'case_id' => $case->getKey(),
            'called_by' => DisciplinaryEvidenceSide::from($data['called_by']),
            'name' => trim($data['name']),
            'age' => $data['age'] ?? null,
            'address' => $this->clean($data['address'] ?? null),
            'occupation' => $this->clean($data['occupation'] ?? null),
            'relationship_disclosure' => $this->clean($data['relationship_disclosure'] ?? null),
            'statement' => $this->clean($data['statement'] ?? null),
            'cross_examination' => $this->clean($data['cross_examination'] ?? null),
            'committee_questions' => $this->clean($data['committee_questions'] ?? null),
            'written_testimony' => (bool) ($data['written_testimony'] ?? false),
            'heard_in_absence_of_other_party' => (bool) ($data['heard_in_absence_of_other_party'] ?? false),
            'heard_at' => $data['heard_at'] ?? null,
            'recorded_by' => $actor->getKey(),
        ])->save();

        $this->record(AuditEventType::DisciplinaryWitnessRecorded, $actor, $case, ['witness_id' => $witness->getKey(), 'called_by' => $witness->called_by->value]);

        return $witness;
    }

    /**
     * Art. 9(1)-(2): a member with a quarrel, blood or marriage relationship
     * with the accused leaves this case only; the head may seat a replacement
     * for this case only. Committee membership history is untouched.
     */
    public function recuse(DisciplinaryCase $case, User $actor, DisciplinaryCaseMember $member, string $reason, ?string $replacementEmployeeId): void
    {
        $this->access->authorize($member->case_id === $case->getKey() && $this->access->canOperate($actor, $case, 'discipline.manage'));
        if ($case->status->isFinal() || $member->recused_at !== null) {
            $this->fail('member', 'recusal_not_allowed');
        }
        if ($replacementEmployeeId !== null && ($replacementEmployeeId === $case->accused_employee_id
            || $case->members()->seated()->where('employee_id', $replacementEmployeeId)->exists())) {
            $this->fail('replacement_employee_id', 'replacement_invalid');
        }

        DB::transaction(function () use ($case, $actor, $member, $reason, $replacementEmployeeId): void {
            $member->forceFill([
                'is_active' => false,
                'recused_at' => now(),
                'recusal_reason' => trim($reason),
                'recusal_decided_by' => $actor->getKey(),
            ])->save();

            if ($replacementEmployeeId !== null) {
                $this->seatMember($case, $replacementEmployeeId, $member->role, StatutoryCommitteeRules::APPOINTED, null, $member->getKey());
            }

            $this->record(AuditEventType::DisciplinaryMemberRecused, $actor, $case, ['member_id' => $member->getKey(), 'replaced' => $replacementEmployeeId !== null]);
        });
    }

    /** Art. 25: the accused's last word before the investigation closes, or their recorded refusal. */
    public function recordFinalStatement(DisciplinaryCase $case, User $actor, ?string $statement, bool $declined): void
    {
        $this->access->authorize($this->access->canRespondAsAccused($actor, $case) || $this->access->canInvestigate($actor, $case));
        $this->requireStatus($case, [DisciplinaryCaseStatus::UnderInvestigation]);
        if (! $declined && trim((string) $statement) === '') {
            $this->fail('final_statement', 'statement_required');
        }

        $case->forceFill([
            'final_statement' => $declined ? null : trim((string) $statement),
            'final_statement_declined' => $declined,
            'final_statement_at' => now(),
        ])->save();

        $this->record(AuditEventType::DisciplinaryFinalStatementRecorded, $actor, $case, ['declined' => $declined, 'by_accused' => $this->access->isAccused($actor, $case)]);
    }

    // ── Recommendation and report ────────────────────────────────────────────

    /**
     * Adopt the recommendation and submit the report to the head (Arts. 8,
     * 26, 27). Formal cases need the Art. 8(2) quorum, a majority (the chair
     * breaks a tie) and a written reason from every dissenting member.
     *
     * @param  array{finding: string, penalty?: string|null, penalty_amount?: int|null, gravity_assessment?: string|null, conduct_assessment?: string|null, prior_record_assessment?: string|null, reasoning: string, votes?: array<string, array{vote: string, dissent_reason?: string|null}>}  $data
     */
    public function adoptRecommendation(DisciplinaryCase $case, User $actor, array $data): DisciplinaryRecommendation
    {
        $this->access->authorize($this->access->canRecommend($actor, $case));
        $this->requireStatus($case, [DisciplinaryCaseStatus::UnderInvestigation]);

        $hasDefence = $case->responses()->exists() || $case->in_absentia;
        if (! $hasDefence) {
            $this->fail('response', 'response_required');
        }
        // Art. 25: before the committee closes its investigation the accused has had the chance of a final statement.
        if ($case->track === DisciplinaryTrack::Formal && ! $case->in_absentia && $case->final_statement_at === null) {
            $this->fail('final_statement', 'final_statement_required');
        }

        $finding = DisciplinaryFinding::from($data['finding']);
        [$penalty, $amount] = $this->validatedPenalty($case, $finding, $data['penalty'] ?? null, $data['penalty_amount'] ?? null);
        if ($finding === DisciplinaryFinding::Responsible) {
            foreach (['gravity_assessment', 'conduct_assessment', 'prior_record_assessment'] as $factor) {
                // Art. 26(1)(a)-(c): every factor is addressed in writing.
                if (trim((string) ($data[$factor] ?? '')) === '') {
                    $this->fail($factor, 'factor_required');
                }
            }
        }

        $tally = $case->track === DisciplinaryTrack::Formal
            ? $this->tallyVotes($case, $data['votes'] ?? [])
            : ['present' => 1, 'for' => 1, 'against' => 0, 'tie' => false, 'votes' => []];

        return DB::transaction(function () use ($case, $actor, $data, $finding, $penalty, $amount, $tally): DisciplinaryRecommendation {
            $case->recommendations()->where('status', 'adopted')->update(['status' => 'superseded']);

            $recommendation = new DisciplinaryRecommendation;
            $recommendation->forceFill([
                'case_id' => $case->getKey(),
                'version' => (int) $case->recommendations()->max('version') + 1,
                'finding' => $finding,
                'recommended_penalty' => $penalty,
                'penalty_amount' => $amount,
                'gravity_assessment' => $this->clean($data['gravity_assessment'] ?? null),
                'conduct_assessment' => $this->clean($data['conduct_assessment'] ?? null),
                'prior_record_assessment' => $this->clean($data['prior_record_assessment'] ?? null),
                'reasoning' => trim($data['reasoning']),
                'status' => 'adopted',
                'members_present' => $tally['present'],
                'votes_for' => $tally['for'],
                'votes_against' => $tally['against'],
                'tie_broken_by_chair' => $tally['tie'],
                'prepared_by' => $actor->getKey(),
                'adopted_at' => now(),
            ])->save();

            foreach ($tally['votes'] as $memberId => $vote) {
                $recommendation->votes()->forceCreate([
                    'case_member_id' => $memberId,
                    'vote' => $vote['vote'],
                    'dissent_reason' => $vote['dissent_reason'],
                ]);
            }

            $now = now();
            $case->forceFill(['status' => DisciplinaryCaseStatus::ReportSubmitted, 'report_submitted_at' => $now])->save();
            $this->deadlines->complete($case, Regulation201::DISCIPLINE_INVESTIGATION, $now);
            $this->deadlines->complete($case, Regulation201::DISCIPLINE_INVESTIGATION_EXTENDED, $now);
            // Art. 28 timings attach to the committee report (Art. 27); Art. 13 sets none for the expedited route.
            if ($case->track === DisciplinaryTrack::Formal) {
                $this->deadlines->snapshot($case, Regulation201::DISCIPLINE_HEAD_DECISION, $now);
            }

            $this->transition($case, DisciplinaryCaseStatus::UnderInvestigation, DisciplinaryCaseStatus::ReportSubmitted, $actor, null);
            $this->record(AuditEventType::DisciplinaryRecommendationAdopted, $actor, $case, [
                'recommendation_id' => $recommendation->getKey(), 'version' => $recommendation->version,
                'finding' => $finding->value, 'penalty' => $penalty?->value, 'votes_for' => $tally['for'], 'votes_against' => $tally['against'],
            ]);
            $this->record(AuditEventType::DisciplinaryReportSubmitted, $actor, $case, ['report_submitted_at' => $now->toIso8601String()]);

            return $recommendation;
        });
    }

    /** Art. 27(1): extend to two months because additional evidence must be gathered. */
    public function requireAdditionalEvidence(DisciplinaryCase $case, User $actor): void
    {
        $this->access->authorize($this->access->canInvestigate($actor, $case));
        $this->requireStatus($case, [DisciplinaryCaseStatus::Charged, DisciplinaryCaseStatus::UnderInvestigation]);
        if ($case->track !== DisciplinaryTrack::Formal || $case->additional_evidence_required) {
            $this->fail('additional_evidence_required', 'already_extended');
        }

        DB::transaction(function () use ($case, $actor): void {
            $case->forceFill(['additional_evidence_required' => true])->save();
            // Same start (the charge date); the one-month milestone is superseded, not edited.
            $case->deadlines()->where('rule_key', Regulation201::DISCIPLINE_INVESTIGATION)->whereNull('completed_at')
                ->whereNull('superseded_at')->update(['superseded_at' => now()]);
            $this->deadlines->snapshot($case, Regulation201::DISCIPLINE_INVESTIGATION_EXTENDED, $case->charged_at ?? now());
            $this->record(AuditEventType::DisciplinaryChargeRecorded, $actor, $case, ['additional_evidence_required' => true]);
        });
    }

    // ── Head decision and delivery ───────────────────────────────────────────

    /**
     * Art. 28(1): within 5 working days the head approves, modifies or
     * overturns the recommendation, or orders re-examination for sufficient
     * reason. The recommendation itself is never edited.
     *
     * @param  array{action: string, finding?: string|null, penalty?: string|null, penalty_amount?: int|null, reason: string}  $data
     */
    public function decide(DisciplinaryCase $case, User $actor, array $data): DisciplinaryDecision
    {
        $this->access->authorize($this->access->canOperate($actor, $case, 'discipline.decide') && $this->access->seat($actor, $case) === null);
        $this->requireStatus($case, [DisciplinaryCaseStatus::ReportSubmitted]);

        $recommendation = $case->currentRecommendation();
        $action = DisciplinaryHeadAction::from($data['action']);

        if ($action === DisciplinaryHeadAction::Approve) {
            $finding = $recommendation?->finding;
            $penalty = $recommendation?->recommended_penalty;
            $amount = $recommendation?->penalty_amount;
        } elseif ($action === DisciplinaryHeadAction::Reinvestigate) {
            [$finding, $penalty, $amount] = [null, null, null];
        } else {
            $finding = DisciplinaryFinding::from((string) ($data['finding'] ?? ''));
            [$penalty, $amount] = $this->validatedPenalty($case, $finding, $data['penalty'] ?? null, $data['penalty_amount'] ?? null);
        }

        return DB::transaction(function () use ($case, $actor, $data, $recommendation, $action, $finding, $penalty, $amount): DisciplinaryDecision {
            $now = now();
            $decision = new DisciplinaryDecision;
            $decision->forceFill([
                'case_id' => $case->getKey(),
                'recommendation_id' => $recommendation?->getKey(),
                'action' => $action,
                'finding' => $finding,
                'penalty' => $penalty,
                'penalty_amount' => $amount,
                'reason' => trim($data['reason']),
                'decided_by' => $actor->getKey(),
                'decided_by_employee_id' => $actor->employee_id,
                'decided_at' => $now,
            ])->save();

            $this->deadlines->complete($case, Regulation201::DISCIPLINE_HEAD_DECISION, $now);

            if ($action === DisciplinaryHeadAction::Reinvestigate) {
                $case->forceFill(['status' => DisciplinaryCaseStatus::UnderInvestigation, 'report_submitted_at' => null])->save();
                $this->transition($case, DisciplinaryCaseStatus::ReportSubmitted, DisciplinaryCaseStatus::UnderInvestigation, $actor, $decision->reason);
            } else {
                $case->forceFill(['status' => DisciplinaryCaseStatus::Decided, 'decided_at' => $now])->save();
                if ($case->track === DisciplinaryTrack::Formal) {
                    $this->deadlines->snapshot($case, Regulation201::DISCIPLINE_DECISION_DELIVERY, $now);
                }
                $this->transition($case, DisciplinaryCaseStatus::ReportSubmitted, DisciplinaryCaseStatus::Decided, $actor, null);
            }

            $this->record(AuditEventType::DisciplinaryDecisionIssued, $actor, $case, [
                'decision_id' => $decision->getKey(), 'action' => $action->value, 'finding' => $finding?->value, 'penalty' => $penalty?->value,
            ]);

            return $decision;
        });
    }

    /**
     * Art. 28(3)/(5) and Art. 29: record the actual delivery of the signed,
     * sealed decision. Fines and demotion take effect 30 days after notice,
     * dismissal on delivery — both suspended by a timely appeal.
     *
     * @param  array{delivered_at: string, method: string, acknowledged_at?: string|null}  $data
     */
    public function recordDelivery(DisciplinaryCase $case, User $actor, array $data): void
    {
        $this->access->authorize($this->access->canOperate($actor, $case, 'discipline.deliver_decision'));
        $this->requireStatus($case, [DisciplinaryCaseStatus::Decided]);

        $decision = $case->currentDecision();
        $deliveredAt = Carbon::parse($data['delivered_at']);
        if ($decision === null || $deliveredAt->isFuture() || $deliveredAt->lt($decision->decided_at)) {
            $this->fail('delivered_at', 'delivery_date_invalid');
        }

        DB::transaction(function () use ($case, $actor, $data, $decision, $deliveredAt): void {
            $effective = match (true) {
                $decision->penalty === null => null,
                $decision->penalty->takesEffectOnDelivery() => $deliveredAt->toDateString(),
                $decision->penalty->amountUnit() !== null => $this->deadlines->dueAt(Regulation201::DISCIPLINE_PENALTY_EFFECTIVE, $deliveredAt)->toDateString(),
                default => $deliveredAt->toDateString(),
            };

            $decision->forceFill([
                'delivery_method' => $data['method'],
                'delivered_at' => $deliveredAt,
                'delivered_by' => $actor->getKey(),
                'acknowledged_at' => $data['acknowledged_at'] ?? null,
                'effective_on' => $effective,
            ])->save();

            $case->forceFill(['status' => DisciplinaryCaseStatus::DecisionDelivered, 'delivered_at' => $deliveredAt])->save();
            $this->deadlines->complete($case, Regulation201::DISCIPLINE_DECISION_DELIVERY, $deliveredAt);
            if ($data['method'] === 'posted') {
                $this->deadlines->snapshot($case, Regulation201::DISCIPLINE_DECISION_POSTING, $deliveredAt);
            }

            $this->transition($case, DisciplinaryCaseStatus::Decided, DisciplinaryCaseStatus::DecisionDelivered, $actor, null);
            $this->record(AuditEventType::DisciplinaryDecisionDelivered, $actor, $case, [
                'decision_id' => $decision->getKey(), 'method' => $data['method'], 'delivered_at' => $deliveredAt->toIso8601String(), 'effective_on' => $effective,
            ]);
        });
    }

    // ── Ending a case ────────────────────────────────────────────────────────

    /** Art. 17(4)-(5): withdrawn in writing before a recommendation; cannot be re-filed on the same matter. */
    public function discontinue(DisciplinaryCase $case, User $actor, string $reason): void
    {
        $this->access->authorize($this->access->canOperate($actor, $case, 'discipline.charge'));
        $this->requireStatus($case, [DisciplinaryCaseStatus::Draft, DisciplinaryCaseStatus::Charged, DisciplinaryCaseStatus::UnderInvestigation]);

        $this->end($case, $actor, DisciplinaryCaseStatus::Discontinued, ['discontinued_at' => now(), 'discontinuance_reason' => trim($reason)], AuditEventType::DisciplinaryCaseDiscontinued, $reason);
    }

    /**
     * Art. 17(1): the accused's service ended for any reason other than
     * resignation. Resignation (Art. 17(2)) does not end the case — it
     * continues in absence via proceedInAbsentia().
     */
    public function terminateForEndOfService(DisciplinaryCase $case, User $actor, string $reason): void
    {
        $this->access->authorize($this->access->canOperate($actor, $case, 'discipline.manage'));
        if ($case->status->isFinal() || in_array($case->status, [DisciplinaryCaseStatus::Decided, DisciplinaryCaseStatus::DecisionDelivered], true)) {
            $this->fail('status', 'invalid_status');
        }

        $this->end($case, $actor, DisciplinaryCaseStatus::Terminated, ['terminated_at' => now(), 'termination_reason' => trim($reason)], AuditEventType::DisciplinaryCaseTerminated, $reason);
    }

    public function close(DisciplinaryCase $case, User $actor): void
    {
        $this->access->authorize($this->access->canOperate($actor, $case, 'discipline.manage'));
        $this->requireStatus($case, [DisciplinaryCaseStatus::DecisionDelivered]);

        $this->end($case, $actor, DisciplinaryCaseStatus::Closed, ['closed_at' => now()], AuditEventType::DisciplinaryCaseClosed, null);
    }

    // ── Internals ────────────────────────────────────────────────────────────

    /**
     * Statutory penalty for a finding. "Not established" carries no penalty;
     * an expedited (minor) case can carry only an Art. 10 penalty — a serious
     * penalty needs the committee route (Arts. 11(4), 13(5)).
     *
     * @return array{0: DisciplinaryPenalty|null, 1: int|null}
     */
    private function validatedPenalty(DisciplinaryCase $case, DisciplinaryFinding $finding, ?string $penaltyValue, mixed $amount): array
    {
        if ($finding === DisciplinaryFinding::NotEstablished) {
            if ($penaltyValue !== null && $penaltyValue !== '') {
                $this->fail('penalty', 'no_penalty_without_finding');
            }

            return [null, null];
        }

        $penalty = DisciplinaryPenalty::tryFrom((string) $penaltyValue) ?? $this->fail('penalty', 'penalty_required');
        if ($case->track === DisciplinaryTrack::Expedited && $penalty->severity() === DisciplinarySeverity::Serious) {
            $this->fail('penalty', 'serious_penalty_needs_committee');
        }

        if ($penalty->maxAmount() === null) {
            return [$penalty, null];
        }
        $amount = (int) $amount;
        if ($amount < 1 || $amount > $penalty->maxAmount()) {
            $this->fail('penalty_amount', 'penalty_amount_range');
        }

        return [$penalty, $amount];
    }

    /**
     * @param  array<string, array{vote: string, dissent_reason?: string|null}>  $votes
     * @return array{present: int, for: int, against: int, tie: bool, votes: array<string, array{vote: string, dissent_reason: string|null}>}
     */
    private function tallyVotes(DisciplinaryCase $case, array $votes): array
    {
        $seated = $case->members()->seated()->where('role', '!=', DisciplinaryCaseRole::Investigator->value)->get()->keyBy('id');
        $cast = [];
        foreach ($votes as $memberId => $vote) {
            if (! $seated->has($memberId) || ! in_array($vote['vote'] ?? null, ['for', 'against'], true)) {
                $this->fail('votes', 'vote_invalid');
            }
            $cast[$memberId] = ['vote' => $vote['vote'], 'dissent_reason' => $this->clean($vote['dissent_reason'] ?? null)];
        }

        $chair = $seated->first(fn (DisciplinaryCaseMember $m): bool => $m->role === DisciplinaryCaseRole::Chairperson);
        $chairPresent = $chair !== null && isset($cast[$chair->getKey()]) ? 1 : 0;
        if (! $this->committees->quorumMet($chairPresent, count($cast) - $chairPresent)) {
            $this->fail('votes', 'quorum_not_met');
        }

        $for = count(array_filter($cast, fn (array $v): bool => $v['vote'] === 'for'));
        $against = count($cast) - $for;
        $result = $this->committees->tally($for, $against, $cast[$chair->getKey()]['vote'] === 'for');
        if (! $result['adopted']) {
            $this->fail('votes', 'recommendation_not_carried');
        }

        foreach ($cast as $vote) {
            // Art. 8(4): the dissenting member states the reason in writing.
            if ($vote['vote'] === 'against' && $vote['dissent_reason'] === null) {
                $this->fail('votes', 'dissent_reason_required');
            }
        }

        return ['present' => count($cast), 'for' => $for, 'against' => $against, 'tie' => $result['tie_broken_by_chair'], 'votes' => $cast];
    }

    private function seatMember(DisciplinaryCase $case, string $employeeId, DisciplinaryCaseRole $role, ?string $source, ?string $committeeMemberId, ?string $replaces = null): DisciplinaryCaseMember
    {
        $seat = new DisciplinaryCaseMember;
        $seat->forceFill([
            'case_id' => $case->getKey(),
            'committee_member_id' => $committeeMemberId,
            'employee_id' => $employeeId,
            'role' => $role,
            'selection_source' => $source,
            'is_active' => true,
            'replaces_member_id' => $replaces,
        ])->save();

        return $seat;
    }

    private function moveToInvestigation(DisciplinaryCase $case, User $actor, ?string $reason = null): void
    {
        if ($case->status === DisciplinaryCaseStatus::Charged) {
            $case->forceFill(['status' => DisciplinaryCaseStatus::UnderInvestigation])->save();
            $this->transition($case, DisciplinaryCaseStatus::Charged, DisciplinaryCaseStatus::UnderInvestigation, $actor, $reason);
        }
    }

    /** @param  array<string, mixed>  $attributes */
    private function end(DisciplinaryCase $case, User $actor, DisciplinaryCaseStatus $to, array $attributes, AuditEventType $event, ?string $reason): void
    {
        DB::transaction(function () use ($case, $actor, $to, $attributes, $event, $reason): void {
            $from = $case->status;
            $case->forceFill(['status' => $to, ...$attributes])->save();
            $case->deadlines()->whereNull('completed_at')->whereNull('superseded_at')->update(['superseded_at' => now()]);
            $this->transition($case, $from, $to, $actor, $reason);
            $this->record($event, $actor, $case, ['from' => $from->value, 'to' => $to->value]);
        });
    }

    private function transition(DisciplinaryCase $case, ?DisciplinaryCaseStatus $from, DisciplinaryCaseStatus $to, User $actor, ?string $reason): void
    {
        $case->history()->forceCreate([
            'from_status' => $from?->value,
            'to_status' => $to->value,
            'actor_id' => $actor->getKey(),
            'reason' => $reason === null ? null : trim($reason),
            'created_at' => now(),
        ]);
    }

    /** @param  list<DisciplinaryCaseStatus>  $allowed */
    private function requireStatus(DisciplinaryCase $case, array $allowed): void
    {
        if (! in_array($case->status, $allowed, true)) {
            $this->fail('status', 'invalid_status');
        }
    }

    /** @param  array<string, mixed>  $new */
    private function record(AuditEventType $event, User $actor, Model $subject, array $new): void
    {
        $this->audit->execute($event, $actor, $subject, $subject->getAttribute('organization_id'), null, $new, null, app()->runningInConsole() ? null : request());
    }

    private function currentAssignment(Employee $employee): ?EmployeeAssignment
    {
        return EmployeeAssignment::query()
            ->where('employee_id', $employee->getKey())
            ->where('is_current', true)
            ->where('assignment_status', AssignmentStatus::Active->value)
            ->latest('effective_from')
            ->first();
    }

    private function clean(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }

    private function fail(string $field, string $code): never
    {
        throw ValidationException::withMessages([$field => __('discipline.errors.'.$code)]);
    }
}

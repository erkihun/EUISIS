<?php

declare(strict_types=1);

namespace Modules\Court\Application\Services;

use Modules\Court\Domain\Models\Applicant;
use Modules\Court\Domain\Models\CaseApplicant;
use Modules\Court\Domain\Models\CaseRepresentative;
use Modules\Court\Domain\Models\CourtStaffMember as User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * The parties on the applicant's side of a case (EUISIS ADR 0013 #13):
 *
 * - the lead applicant (`court_cases.applicant_id`, also a `lead` row);
 * - co-applicants, invited by the lead and parties once they confirm;
 * - interveners, who apply and are parties once the case's judge admits them;
 * - lawyers representing any of them, once the registry accepts the power of attorney;
 * - and cases merged into another by a judge or registrar.
 *
 * Who may follow a case (read it, write to the court, add documents, reply to
 * responses) is every party and their accepted lawyer; who may change it
 * (correct it, remove its evidence or witnesses) is the lead and the lead's lawyer.
 */
final class CaseParties
{
    /** Case ids an applicant account may follow. */
    public static function caseIdsFor(int $applicantId): Builder
    {
        return DB::table('court_cases')->select('id')->where('applicant_id', $applicantId)
            ->union(DB::table('court_case_applicants')->select('case_id')->where('applicant_id', $applicantId)->whereIn('status', CaseApplicant::PARTY_STATUSES))
            // A lawyer follows the cases of those they represent, including one they filed that the employee has yet to confirm.
            ->union(DB::table('court_case_representatives as r')->select('r.case_id')
                ->join('court_case_applicants as p', 'p.id', '=', 'r.case_applicant_id')
                ->where('r.lawyer_applicant_id', $applicantId)->where('r.status', 'accepted')
                ->whereIn('p.status', [...CaseApplicant::PARTY_STATUSES, 'invited']));
    }

    /** Case ids an applicant account may change: their own as lead, or the lead's as their accepted lawyer. */
    public static function leadCaseIdsFor(int $applicantId): Builder
    {
        return DB::table('court_cases')->select('id')->where('applicant_id', $applicantId)
            ->union(DB::table('court_case_representatives as r')->select('r.case_id')
                ->join('court_case_applicants as p', 'p.id', '=', 'r.case_applicant_id')
                ->where('r.lawyer_applicant_id', $applicantId)->where('r.status', 'accepted')
                ->where('p.role', CaseApplicant::ROLE_LEAD)->whereIn('p.status', ['active', 'invited']));
    }

    public static function canFollow(int $applicantId, int $caseId): bool
    {
        return DB::table('court_cases')->where('id', $caseId)->whereIn('id', self::caseIdsFor($applicantId))->exists();
    }

    public static function canChange(int $applicantId, int $caseId): bool
    {
        return DB::table('court_cases')->where('id', $caseId)->whereIn('id', self::leadCaseIdsFor($applicantId))->exists();
    }

    /** The role an account follows a case in: lead, co_applicant, intervener or lawyer; null when none. */
    public static function roleOf(int $applicantId, int $caseId): ?string
    {
        if (DB::table('court_cases')->where('id', $caseId)->where('applicant_id', $applicantId)->exists()) {
            return CaseApplicant::ROLE_LEAD;
        }
        $role = DB::table('court_case_applicants')->where('case_id', $caseId)->where('applicant_id', $applicantId)
            ->whereIn('status', CaseApplicant::PARTY_STATUSES)->value('role');

        return $role ?? (self::canFollow($applicantId, $caseId) ? 'lawyer' : null);
    }

    /** The lead row of a case, written when a case from before parties existed lacks one. */
    public function ensureLead(int $caseId): ?CaseApplicant
    {
        $lead = CaseApplicant::query()->where('case_id', $caseId)->where('role', CaseApplicant::ROLE_LEAD)->first();
        if ($lead !== null) {
            return $lead;
        }
        $case = DB::table('court_cases as c')->leftJoin('court_applicants as a', 'a.id', '=', 'c.applicant_id')
            ->where('c.id', $caseId)->first(['c.title', 'c.applicant_id', 'a.euisis_employee_uuid', 'a.first_name', 'a.middle_name', 'a.last_name']);
        if ($case === null || $case->applicant_id === null) {
            return null;
        }

        return $this->addLead($caseId, (int) $case->applicant_id, $case->euisis_employee_uuid,
            trim((string) $case->title) !== '' ? (string) $case->title : self::fullName($case), 'backfill');
    }

    public function addLead(int $caseId, ?int $applicantId, ?string $employeeUuid, string $name, string $source, string $status = 'active'): CaseApplicant
    {
        return CaseApplicant::query()->create([
            'case_id' => $caseId, 'applicant_id' => $applicantId, 'euisis_employee_uuid' => $employeeUuid,
            'name' => $name, 'role' => CaseApplicant::ROLE_LEAD, 'status' => $status, 'source' => $source,
        ]);
    }

    /** A co-applicant the lead names: invited until they confirm in the EUISIS employee portal. */
    public function invite(int $caseId, string $employeeUuid, string $name, ?int $invitedBy, string $source = 'filing'): CaseApplicant
    {
        $existing = CaseApplicant::query()->where('case_id', $caseId)->where('euisis_employee_uuid', $employeeUuid)->first();
        if ($existing !== null) {
            return $existing;
        }
        $applicantId = Applicant::query()->where('euisis_employee_uuid', $employeeUuid)->value('id');

        return CaseApplicant::query()->create([
            'case_id' => $caseId, 'applicant_id' => $applicantId, 'euisis_employee_uuid' => $employeeUuid, 'name' => $name,
            'role' => CaseApplicant::ROLE_CO_APPLICANT, 'status' => 'invited', 'source' => $source, 'invited_by_applicant_id' => $invitedBy,
        ]);
    }

    /**
     * The invited employee joins the case or declines; joining links their court account.
     * Joining confirms the lawyer who filed for them (as the lead or as one of several
     * clients). They may also name a lawyer of their own from the register, with the power of
     * attorney the registry then checks.
     */
    public function respond(CaseApplicant $party, Applicant $applicant, bool $accept, ?Applicant $lawyer = null, ?string $powerOfAttorneyPath = null): void
    {
        if ($party->status !== 'invited') {
            throw ValidationException::withMessages(['invitation' => [__('court::case_parties.errors.not_invited')]]);
        }
        $party->forceFill([
            'applicant_id' => $applicant->id,
            'status' => $accept ? 'active' : 'declined',
            'responded_at' => now(),
        ])->save();
        if ($accept && $party->role === CaseApplicant::ROLE_LEAD) {
            // A case a lawyer filed for the employee: the employee is now its applicant.
            DB::table('court_cases')->where('id', $party->case_id)->whereNull('applicant_id')->update(['applicant_id' => $applicant->id, 'updated_at' => now()]);
        }
        if ($accept) {
            CaseRepresentative::query()->where('case_applicant_id', $party->id)->whereNull('confirmed_by_party_at')->update(['confirmed_by_party_at' => now()]);
            if ($lawyer !== null) {
                $this->addRepresentative($party, $lawyer, $powerOfAttorneyPath, confirmedByParty: true);
            }
        }
        $this->audit((int) $party->case_id, $accept ? 'party_joined' : 'party_declined', 'applicant', (int) $applicant->id, ['party_id' => $party->id, 'role' => $party->role]);
    }

    /** An employee asks to intervene in a case: the judge decides. */
    public function requestIntervention(int $caseId, Applicant $applicant, string $statement): CaseApplicant
    {
        $case = DB::table('court_cases')->where('id', $caseId)->first(['id', 'status', 'merged_into_case_id']);
        if ($case === null || in_array($case->status, ['closed', 'dismissed'], true) || $case->merged_into_case_id !== null) {
            throw ValidationException::withMessages(['case_number' => [__('court::case_parties.errors.case_not_open')]]);
        }
        $existing = CaseApplicant::query()->where('case_id', $caseId)
            ->where(fn ($q) => $q->where('applicant_id', $applicant->id)->when($applicant->euisis_employee_uuid, fn ($q) => $q->orWhere('euisis_employee_uuid', $applicant->euisis_employee_uuid)))
            ->whereNotIn('status', ['declined', 'refused', 'removed'])->first();
        if ($existing !== null || DB::table('court_cases')->where('id', $caseId)->where('applicant_id', $applicant->id)->exists()) {
            throw ValidationException::withMessages(['case_number' => [__('court::case_parties.errors.already_party')]]);
        }

        $party = CaseApplicant::query()->updateOrCreate(
            ['case_id' => $caseId, 'euisis_employee_uuid' => $applicant->euisis_employee_uuid ?? null, 'applicant_id' => $applicant->id],
            ['name' => self::fullName($applicant), 'role' => CaseApplicant::ROLE_INTERVENER, 'status' => 'requested', 'source' => 'intervention',
                'statement' => $statement, 'decided_by_user_id' => null, 'decided_at' => null, 'decision_note' => null],
        );
        $this->audit($caseId, 'intervention_requested', 'applicant', (int) $applicant->id, ['party_id' => $party->id]);

        return $party;
    }

    /** Only the case's judge decides an intervention; while no judge is set, any judge. */
    public function canDecideIntervention(User $user, int $caseId): bool
    {
        $judgeId = DB::table('court_cases')->where('id', $caseId)->value('judge_id');
        if ($judgeId !== null) {
            return (int) $judgeId === (int) $user->id;
        }

        return ($user->user_type ?? null) === 'judge' || $user->hasRole('judge');
    }

    public function decideIntervention(CaseApplicant $party, User $user, bool $admit, ?string $note): void
    {
        if ($party->role !== CaseApplicant::ROLE_INTERVENER || $party->status !== 'requested') {
            throw ValidationException::withMessages(['decision' => [__('court::case_parties.errors.not_requested')]]);
        }
        if (! $this->canDecideIntervention($user, (int) $party->case_id)) {
            abort(403, __('court::case_parties.errors.judge_only'));
        }
        if (! $admit && trim((string) $note) === '') {
            throw ValidationException::withMessages(['note' => [__('court::case_parties.errors.reason_required')]]);
        }
        $party->forceFill(['status' => $admit ? 'admitted' : 'refused', 'decided_by_user_id' => $user->id, 'decided_at' => now(), 'decision_note' => $note])->save();
        $this->audit((int) $party->case_id, $admit ? 'intervention_admitted' : 'intervention_refused', 'user', (int) $user->id, ['party_id' => $party->id, 'note' => $note]);
    }

    /** A lawyer for a party, with their power of attorney; the registry checks it. */
    public function addRepresentative(CaseApplicant $party, Applicant $lawyer, ?string $powerOfAttorneyPath, bool $confirmedByParty): CaseRepresentative
    {
        if (! self::isVerifiedLawyer($lawyer)) {
            throw ValidationException::withMessages(['lawyer' => [__('court::case_parties.errors.lawyer_not_verified')]]);
        }

        return CaseRepresentative::query()->updateOrCreate(
            ['case_applicant_id' => $party->id, 'lawyer_applicant_id' => $lawyer->id],
            ['case_id' => $party->case_id, 'power_of_attorney_path' => $powerOfAttorneyPath, 'status' => 'pending',
                'confirmed_by_party_at' => $confirmedByParty ? now() : null],
        );
    }

    public function verifyRepresentative(CaseRepresentative $representative, User $user, bool $accept, ?string $note): void
    {
        if (! $accept && trim((string) $note) === '') {
            throw ValidationException::withMessages(['note' => [__('court::case_parties.errors.reason_required')]]);
        }
        $representative->forceFill(['status' => $accept ? 'accepted' : 'refused', 'verified_by_user_id' => $user->id, 'verified_at' => now(), 'note' => $note])->save();
        $this->audit((int) $representative->case_id, $accept ? 'representation_accepted' : 'representation_refused', 'user', (int) $user->id, ['representative_id' => $representative->id]);
    }

    /**
     * The registry records a lawyer for a party from the power of attorney the party signed
     * (a client who came to the registry, a co-applicant's lawyer): accepted at once, since
     * the registry is who checks a power of attorney. A lawyer already accepted for the
     * party is refused as a duplicate; a refused or revoked one may be recorded again.
     */
    public function addRepresentativeByRegistry(CaseApplicant $party, Applicant $lawyer, string $powerOfAttorneyPath, User $user, ?string $note): CaseRepresentative
    {
        if (! in_array($party->status, [...CaseApplicant::PARTY_STATUSES, 'invited'], true)) {
            throw ValidationException::withMessages(['lawyer' => [__('court::case_parties.errors.party_not_represented')]]);
        }
        $existing = CaseRepresentative::query()->where('case_applicant_id', $party->id)->where('lawyer_applicant_id', $lawyer->id)->first();
        if ($existing !== null && in_array($existing->status, ['pending', 'accepted'], true)) {
            throw ValidationException::withMessages(['lawyer' => [__('court::case_parties.errors.already_represented')]]);
        }
        $representative = $this->addRepresentative($party, $lawyer, $powerOfAttorneyPath, confirmedByParty: true);
        $representative->forceFill(['status' => 'accepted', 'verified_by_user_id' => $user->id, 'verified_at' => now(), 'note' => filled($note) ? trim((string) $note) : null])->save();
        $this->audit((int) $party->case_id, 'representation_added', 'user', (int) $user->id, ['party_id' => $party->id, 'representative_id' => $representative->id]);

        return $representative;
    }

    public static function isVerifiedLawyer(Applicant $applicant): bool
    {
        return (bool) $applicant->is_lawyer && $applicant->lawyer_verified_at !== null && (bool) $applicant->is_active;
    }

    /**
     * Why `$source` cannot be merged into `$target` (case_parties.merge.*), or null when it can:
     * both still open and not merged, and against the same respondent.
     */
    public function mergeProblem(object $target, object $source): ?string
    {
        return match (true) {
            (int) $target->id === (int) $source->id => 'same_case',
            $target->merged_into_case_id !== null || $source->merged_into_case_id !== null => 'already_merged',
            in_array($target->status, ['closed', 'dismissed'], true), in_array($source->status, ['closed', 'dismissed'], true) => 'not_open',
            ! self::sameRespondent($target, $source) => 'other_respondent',
            default => null,
        };
    }

    public static function sameRespondent(object $a, object $b): bool
    {
        if (! empty($a->respondent_organization_uuid) && ! empty($b->respondent_organization_uuid)) {
            return $a->respondent_organization_uuid === $b->respondent_organization_uuid;
        }

        return mb_strtolower(trim((string) $a->respondent_name)) !== '' && mb_strtolower(trim((string) $a->respondent_name)) === mb_strtolower(trim((string) $b->respondent_name));
    }

    /**
     * Merges cases into `$targetId`: their parties join it as co-applicants
     * (with their lawyers), their documents, witnesses and coming hearings move
     * to it, and each is closed as merged into it. Nothing is deleted.
     *
     * @param  list<int>  $sourceIds
     * @return list<string> the merged case numbers
     */
    public function merge(int $targetId, array $sourceIds, User $user, string $note): array
    {
        return DB::transaction(function () use ($targetId, $sourceIds, $user, $note): array {
            $columns = ['id', 'case_number', 'status', 'applicant_id', 'respondent_name', 'merged_into_case_id'];
            if (Schema::hasColumn('court_cases', 'respondent_organization_uuid')) {
                $columns[] = 'respondent_organization_uuid';
            }
            $target = DB::table('court_cases')->lockForUpdate()->where('id', $targetId)->first($columns);
            abort_if($target === null, 404);
            $this->ensureLead($targetId);
            $merged = [];

            foreach (array_unique($sourceIds) as $sourceId) {
                $source = DB::table('court_cases')->lockForUpdate()->where('id', $sourceId)->first($columns);
                if ($source === null) {
                    throw ValidationException::withMessages(['cases' => [__('court::case_parties.merge.not_found')]]);
                }
                if (($problem = $this->mergeProblem($target, $source)) !== null) {
                    throw ValidationException::withMessages(['cases' => [__("court::case_parties.merge.{$problem}", ['number' => $source->case_number])]]);
                }
                $this->ensureLead((int) $source->id);

                foreach (CaseApplicant::query()->where('case_id', $source->id)->whereIn('status', CaseApplicant::PARTY_STATUSES)->get() as $party) {
                    $already = $party->applicant_id === null && $party->euisis_employee_uuid === null ? null : CaseApplicant::query()->where('case_id', $targetId)
                        ->where(fn ($q) => $q->when($party->applicant_id, fn ($q) => $q->where('applicant_id', $party->applicant_id))
                            ->when($party->euisis_employee_uuid, fn ($q) => $q->orWhere('euisis_employee_uuid', $party->euisis_employee_uuid)))
                        ->first();
                    $joined = $already ?? CaseApplicant::query()->create([
                        'case_id' => $targetId, 'applicant_id' => $party->applicant_id, 'euisis_employee_uuid' => $party->euisis_employee_uuid,
                        'name' => $party->name, 'role' => $party->role === CaseApplicant::ROLE_INTERVENER ? CaseApplicant::ROLE_INTERVENER : CaseApplicant::ROLE_CO_APPLICANT,
                        'status' => $party->role === CaseApplicant::ROLE_INTERVENER ? 'admitted' : 'active', 'source' => 'merge', 'merged_from_case_id' => $source->id,
                    ]);
                    foreach (CaseRepresentative::query()->where('case_applicant_id', $party->id)->where('status', 'accepted')->get() as $representative) {
                        CaseRepresentative::query()->firstOrCreate(
                            ['case_applicant_id' => $joined->id, 'lawyer_applicant_id' => $representative->lawyer_applicant_id],
                            ['case_id' => $targetId, 'power_of_attorney_path' => $representative->power_of_attorney_path, 'status' => 'accepted',
                                'confirmed_by_party_at' => $representative->confirmed_by_party_at, 'verified_by_user_id' => $representative->verified_by_user_id,
                                'verified_at' => $representative->verified_at, 'note' => $representative->note],
                        );
                    }
                }

                foreach (['case_evidences', 'case_files', 'case_witnesses'] as $table) {
                    DB::table($table)->where('case_id', $source->id)->update(['case_id' => $targetId]);
                }
                DB::table('court_case_hearings')->where('case_id', $source->id)->where('hearing_at', '>=', now())->update(['case_id' => $targetId]);

                DB::table('court_cases')->where('id', $source->id)->update([
                    'status' => 'closed', 'merged_into_case_id' => $targetId, 'merged_at' => now(), 'merged_by_user_id' => $user->id, 'merge_note' => $note, 'updated_at' => now(),
                ]);
                if ($source->status !== 'closed') {
                    DB::table('court_case_status_logs')->insert(['case_id' => $source->id, 'from_status' => $source->status, 'to_status' => 'closed', 'changed_by_user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
                }
                $this->audit((int) $source->id, 'case_merged_into', 'user', (int) $user->id, ['into' => $target->case_number, 'note' => $note]);
                $this->audit($targetId, 'case_merged_from', 'user', (int) $user->id, ['from' => $source->case_number, 'note' => $note]);
                $merged[] = (string) $source->case_number;
            }

            return $merged;
        });
    }

    public static function fullName(object $person): string
    {
        return collect([$person->first_name ?? null, $person->middle_name ?? null, $person->last_name ?? null])
            ->map(fn ($part): string => trim((string) $part))->filter()->implode(' ');
    }

    /** @param  array<string, mixed>  $meta */
    private function audit(int $caseId, string $action, string $actorType, int $actorId, array $meta): void
    {
        if (Schema::hasTable('court_case_audits')) {
            DB::table('court_case_audits')->insert(['case_id' => $caseId, 'action' => $action, 'actor_type' => $actorType, 'actor_id' => $actorId,
                'meta' => json_encode($meta, JSON_UNESCAPED_UNICODE), 'created_at' => now()]);
        }
    }
}

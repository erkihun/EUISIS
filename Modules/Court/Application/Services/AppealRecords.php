<?php

declare(strict_types=1);

namespace Modules\Court\Application\Services;

use Modules\Court\Domain\Models\Decision;
use Modules\Court\Domain\Models\CourtStaffMember as User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Appeals to the appellate court (EUISIS ADR 0013 #14). The appellate court is
 * outside this system: a party to a closed case (the applicant's side, or the
 * respondent institution) asks for the case record to take there, the registry
 * releases it (the case is then marked as appealed and the party may download
 * the record), and the appellate court's outcome is recorded when it comes back.
 * Each outcome counts for the judges of the panel whose decision it was.
 *
 * Appeal statuses: submitted (asked), approved (released), rejected (refused),
 * closed (outcome recorded). Drafts are the registry's own, from the appeals register.
 */
final class AppealRecords
{
    public const SIDES = ['applicant', 'respondent'];

    public const OUTCOMES = ['confirmed', 'modified', 'reversed'];

    /** Points an outcome scores out of 10 in the judges' evaluation. */
    public const OUTCOME_POINTS = ['confirmed' => 10, 'modified' => 5, 'reversed' => 0];

    /**
     * The published decision of a closed case, which a party may appeal; null
     * while the case is open or has no published decision.
     */
    public function appealableDecision(int $caseId): ?Decision
    {
        $case = DB::table('court_cases')->where('id', $caseId)->first(['id', 'status', 'merged_into_case_id']);
        if ($case === null || $case->merged_into_case_id !== null || ! in_array($case->status, ['closed', 'dismissed'], true)) {
            return null;
        }

        return Decision::query()->where('court_case_id', $caseId)->orderByDesc('decision_date')->orderByDesc('id')->get()
            ->first(fn (Decision $decision): bool => $decision->isDownloadableByParties());
    }

    /**
     * A party asks for the record to take to the appellate court.
     *
     * @param  string  $via  euisis_employee, euisis_institution, lawyer or registry
     */
    public function request(int $caseId, string $side, string $appellantName, string $grounds, string $via, ?string $reference = null, ?int $applicantId = null, ?User $by = null): object
    {
        if (! in_array($side, self::SIDES, true)) {
            throw ValidationException::withMessages(['appellant_type' => [__('court::appeal_records.errors.side')]]);
        }
        $decision = $this->appealableDecision($caseId);
        if ($decision === null) {
            throw ValidationException::withMessages(['case' => [__('court::appeal_records.errors.not_appealable')]]);
        }
        $open = DB::table('court_appeals')->where('court_case_id', $caseId)->where('appellant_type', $side)
            ->whereIn('status', ['draft', 'submitted', 'under_review', 'approved'])->exists();
        if ($open) {
            throw ValidationException::withMessages(['case' => [__('court::appeal_records.errors.already_requested')]]);
        }

        $caseNumber = (string) DB::table('court_cases')->where('id', $caseId)->value('case_number');

        return DB::transaction(function () use ($caseId, $side, $appellantName, $grounds, $via, $reference, $applicantId, $by, $decision, $caseNumber): object {
            $id = DB::table('court_appeals')->insertGetId([
                'court_case_id' => $caseId,
                'applicant_id' => $applicantId,
                'submitted_by_user_id' => $by?->id,
                'appeal_number' => 'TMP-'.uniqid('', true),
                'title' => __('court::appeal_records.title', ['number' => $caseNumber, 'name' => $appellantName], 'en'),
                'grounds' => $grounds,
                'status' => 'submitted',
                'submitted_at' => now(),
                'appellant_type' => $side,
                'appellant_name' => mb_substr($appellantName, 0, 255),
                'requested_via' => $via,
                'requester_reference' => $reference,
                'decision_id' => $decision->id,
                'judge_user_ids' => json_encode(self::panelJudgeIds($decision, $caseId)),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('court_appeals')->where('id', $id)->update(['appeal_number' => 'APL-'.now()->format('Y').'-'.str_pad((string) $id, 4, '0', STR_PAD_LEFT)]);
            $this->audit($caseId, 'appeal_requested', $by?->id, ['appeal_id' => $id, 'side' => $side, 'via' => $via]);

            return DB::table('court_appeals')->where('id', $id)->first();
        });
    }

    /** The registry lets the party take the record to the appellate court: the case is marked as appealed. */
    public function release(object $appeal, User $user, ?string $note): void
    {
        $this->expect($appeal, ['submitted', 'under_review']);
        DB::transaction(function () use ($appeal, $user, $note): void {
            DB::table('court_appeals')->where('id', $appeal->id)->update([
                'status' => 'approved', 'released_at' => now(), 'released_by_user_id' => $user->id,
                'decided_by_user_id' => $user->id, 'decided_at' => now(), 'decision_notes' => $note, 'updated_at' => now(),
            ]);
            DB::table('court_cases')->where('id', $appeal->court_case_id)->whereNull('appealed_at')->update(['appealed_at' => now(), 'updated_at' => now()]);
            $this->audit((int) $appeal->court_case_id, 'appeal_released', $user->id, ['appeal_id' => $appeal->id]);
        });
    }

    /** The registry refuses the request, with the reason. */
    public function refuse(object $appeal, User $user, ?string $note): void
    {
        $this->expect($appeal, ['submitted', 'under_review']);
        if (trim((string) $note) === '') {
            throw ValidationException::withMessages(['decision_notes' => [__('court::appeal_records.errors.reason_required')]]);
        }
        DB::table('court_appeals')->where('id', $appeal->id)->update([
            'status' => 'rejected', 'decided_by_user_id' => $user->id, 'decided_at' => now(), 'decision_notes' => $note, 'updated_at' => now(),
        ]);
        $this->audit((int) $appeal->court_case_id, 'appeal_refused', $user->id, ['appeal_id' => $appeal->id]);
    }

    /**
     * What the appellate court decided on a released record.
     *
     * @param  array{outcome: string, outcome_date: string, appellate_court?: ?string, appellate_case_number?: ?string, outcome_notes?: ?string}  $data
     */
    public function recordOutcome(object $appeal, User $user, array $data, ?string $documentPath): void
    {
        $this->expect($appeal, ['approved']);
        DB::transaction(function () use ($appeal, $user, $data, $documentPath): void {
            DB::table('court_appeals')->where('id', $appeal->id)->update([
                'status' => 'closed',
                'outcome' => $data['outcome'],
                'outcome_date' => Carbon::parse($data['outcome_date'])->toDateString(),
                'appellate_court' => $data['appellate_court'] ?? null,
                'appellate_case_number' => $data['appellate_case_number'] ?? null,
                'outcome_notes' => $data['outcome_notes'] ?? null,
                'outcome_document_path' => $documentPath,
                'outcome_recorded_by_user_id' => $user->id,
                'outcome_recorded_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('court_cases')->where('id', $appeal->court_case_id)->update(['appeal_outcome' => $data['outcome'], 'updated_at' => now()]);
            $this->audit((int) $appeal->court_case_id, 'appeal_outcome_recorded', $user->id, ['appeal_id' => $appeal->id, 'outcome' => $data['outcome']]);
        });
    }

    /** Whether this side of the case has a released record to download. */
    public function released(int $caseId, string $side, ?string $reference = null): bool
    {
        return DB::table('court_appeals')->where('court_case_id', $caseId)->where('appellant_type', $side)
            ->when($reference !== null, fn ($q) => $q->where('requester_reference', $reference))
            ->whereNotNull('released_at')->exists();
    }

    /**
     * A side's appeals on a case, newest first, as the parties see them.
     *
     * @return list<array<string, mixed>>
     */
    public function forSide(int $caseId, string $side): array
    {
        $iso = fn (mixed $value): ?string => $value ? Carbon::parse($value)->toIso8601String() : null;

        return DB::table('court_appeals')->where('court_case_id', $caseId)->where('appellant_type', $side)->orderByDesc('id')->get()
            ->map(fn (object $appeal): array => [
                'id' => (int) $appeal->id,
                'appeal_number' => (string) $appeal->appeal_number,
                'status' => (string) $appeal->status,
                'grounds' => $appeal->grounds,
                'note' => $appeal->decision_notes,
                'requested_at' => $iso($appeal->submitted_at),
                'released_at' => $iso($appeal->released_at),
                'outcome' => $appeal->outcome,
                'outcome_date' => $appeal->outcome_date,
                'appellate_court' => $appeal->appellate_court,
            ])->values()->all();
    }

    /**
     * A judge's appeal record over a period: how many outcomes came back in it,
     * of which kind, and the score out of 10 (null when none came back).
     *
     * @return array{total: int, confirmed: int, modified: int, reversed: int, score: ?int}
     */
    public static function judgeRecord(int $userId, string $from, string $to): array
    {
        $counts = ['confirmed' => 0, 'modified' => 0, 'reversed' => 0];
        DB::table('court_appeals')->where('status', 'closed')->whereNotNull('outcome')
            ->whereBetween('outcome_date', [Carbon::parse($from)->toDateString(), Carbon::parse($to)->toDateString()])
            ->get(['outcome', 'judge_user_ids'])
            ->filter(fn (object $appeal): bool => in_array($userId, array_map('intval', (array) json_decode((string) $appeal->judge_user_ids, true)), true))
            ->each(function (object $appeal) use (&$counts): void {
                if (isset($counts[$appeal->outcome])) {
                    $counts[$appeal->outcome]++;
                }
            });
        $total = array_sum($counts);
        $points = array_sum(array_map(fn (string $outcome): int => $counts[$outcome] * self::OUTCOME_POINTS[$outcome], array_keys($counts)));

        return ['total' => $total, ...$counts, 'score' => $total > 0 ? (int) round($points / $total) : null];
    }

    /**
     * The judges of the panel that gave the decision; the case's judge when the
     * panel was not recorded.
     *
     * @return list<int>
     */
    public static function panelJudgeIds(Decision $decision, int $caseId): array
    {
        $ids = collect(is_array($decision->panel_judges) ? array_values($decision->panel_judges) : [])
            ->map(fn ($judge): int => (int) (is_array($judge) ? ($judge['admin_user_id'] ?? 0) : 0))
            ->filter(fn (int $id): bool => $id > 0)->unique()->values()->all();
        if ($ids === []) {
            $judge = (int) DB::table('court_cases')->where('id', $caseId)->value('judge_id');
            $ids = $judge > 0 ? [$judge] : [];
        }

        return $ids;
    }

    /** @param  list<string>  $statuses */
    private function expect(object $appeal, array $statuses): void
    {
        if (! in_array($appeal->status, $statuses, true)) {
            throw ValidationException::withMessages(['decision' => [__('court::appeal_records.errors.wrong_stage')]]);
        }
    }

    /** @param  array<string, mixed>  $details */
    private function audit(int $caseId, string $action, ?int $userId, array $details): void
    {
        if (Schema::hasTable('court_case_audits')) {
            DB::table('court_case_audits')->insert(['case_id' => $caseId, 'action' => $action, 'actor_type' => $userId !== null ? 'user' : 'system',
                'actor_id' => $userId, 'meta' => json_encode($details, JSON_UNESCAPED_UNICODE), 'created_at' => now()]);
        }
    }
}

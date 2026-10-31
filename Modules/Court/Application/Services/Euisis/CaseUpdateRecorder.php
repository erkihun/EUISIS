<?php

declare(strict_types=1);

namespace Modules\Court\Application\Services\Euisis;

use Modules\Court\Domain\Models\EuisisCaseUpdate;
use Modules\Court\Domain\Models\EuisisReferral;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Turns changes of referred cases into rows of euisis_case_updates, the list
 * EUISIS polls (docs/euisis-integration.md, Flow 3).
 *
 * The admin screens write cases with query-builder updates, so no model
 * event fires; instead each referred case's current state is compared with
 * what was last reported, whatever wrote it. Runs before every poll and from
 * the scheduled euisis:sync, so a change shows up within minutes.
 */
final class CaseUpdateRecorder
{
    private const SUMMARY_MAX = 1000;

    /** Records a row for every referred case whose state changed; returns how many. */
    public function capture(): int
    {
        $recorded = 0;

        EuisisReferral::query()->orderBy('id')->chunkById(200, function (Collection $referrals) use (&$recorded): void {
            $states = $this->states($referrals->pluck('court_case_id')->all());
            foreach ($referrals as $referral) {
                $state = $states[$referral->court_case_id] ?? null;
                if ($state === null) {
                    continue;
                }
                $previous = (array) ($referral->last_reported_state ?? []);
                $changed = array_keys(array_filter($state, fn ($value, $key) => ($previous[$key] ?? null) !== $value, ARRAY_FILTER_USE_BOTH));
                if ($changed === []) {
                    continue;
                }

                DB::transaction(function () use ($referral, $state, $changed): void {
                    EuisisCaseUpdate::query()->create([
                        'euisis_referral_id' => $referral->id,
                        'court_case_id' => $referral->court_case_id,
                        'case_number' => $state['case_number'],
                        'review_status' => $state['review_status'],
                        'review_reason' => $state['review_reason'],
                        'status' => $state['status'],
                        'next_hearing_at' => $state['hearing_at'],
                        'decision_date' => $state['decision_date'],
                        'decision_summary' => $state['decision_summary'],
                        'changed' => $changed,
                        'created_at' => now(),
                    ]);
                    $referral->forceFill(['last_reported_state' => $state])->save();
                });
                $recorded++;
            }
        });

        return $recorded;
    }

    /** @return array<string, string|null>|null */
    public function stateOf(int $caseId): ?array
    {
        return $this->states([$caseId])[$caseId] ?? null;
    }

    /**
     * What EUISIS is told about each case: review state (with the note when
     * returned or rejected), case status, the next hearing (or the last one
     * when none is ahead), and the latest published decision.
     *
     * @param  list<int>  $caseIds
     * @return array<int, array<string, string|null>>
     */
    private function states(array $caseIds): array
    {
        if ($caseIds === []) {
            return [];
        }

        $cases = DB::table('court_cases')->whereIn('id', $caseIds)
            ->get(['id', 'case_number', 'review_status', 'review_note', 'status', 'first_hearing_date'])->keyBy('id');
        $hearings = DB::table('court_case_hearings')->whereIn('case_id', $caseIds)->orderBy('hearing_at')->get(['case_id', 'hearing_at'])->groupBy('case_id');
        $decisions = DB::table('court_decisions')->whereIn('court_case_id', $caseIds)->where('status', 'published')
            ->orderByDesc('decision_date')->orderByDesc('id')->get(['court_case_id', 'decision_date', 'name', 'description'])->groupBy('court_case_id');

        $now = now();
        $out = [];
        foreach ($cases as $id => $case) {
            $times = ($hearings[$id] ?? collect())->pluck('hearing_at')->map(fn ($t) => (string) $t);
            $ahead = $times->first(fn (string $t) => $t >= $now->toDateTimeString());
            $hearing = $ahead ?? $times->last() ?? ($case->first_hearing_date !== null ? $case->first_hearing_date.' 00:00:00' : null);
            $decision = ($decisions[$id] ?? collect())->first();
            $returned = in_array($case->review_status, ['returned', 'rejected'], true);

            $out[(int) $id] = [
                'case_number' => (string) $case->case_number,
                'review_status' => (string) $case->review_status,
                'review_reason' => $returned && filled($case->review_note) ? Str::limit(trim(strip_tags((string) $case->review_note)), self::SUMMARY_MAX) : null,
                'status' => (string) $case->status,
                'hearing_at' => $hearing !== null ? substr($hearing, 0, 19) : null,
                'decision_date' => $decision?->decision_date !== null ? substr((string) $decision->decision_date, 0, 10) : null,
                'decision_summary' => $decision !== null ? Str::limit(trim(html_entity_decode(strip_tags((string) ($decision->description ?: $decision->name)))), self::SUMMARY_MAX) : null,
            ];
        }

        return $out;
    }
}

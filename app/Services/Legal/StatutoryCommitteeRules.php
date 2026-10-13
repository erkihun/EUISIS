<?php

declare(strict_types=1);

namespace App\Services\Legal;

use App\Enums\CommitteeType;
use App\Enums\Discipline\DisciplinaryFinding;
use App\Enums\Discipline\DisciplinaryPenalty;
use App\Models\DisciplinaryDecision;
use App\Models\EmployeeAssignment;
use App\Models\GrievanceCommittee;
use App\Models\GrievanceCommitteeMember;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Regulation 201/2018 committee rules, applied only to the two statutory
 * committee types. EPMS and Tribunal panels share the committee table but
 * none of these rules.
 *
 *  - Art. 5: disciplinary committee = chair + one member appointed by the
 *    head, two members elected by employees (at least one woman), one HR
 *    representative serving as secretary.
 *  - Art. 30(2): grievance committee = five voting members: chair appointed by
 *    the head, three elected (at least one woman), one HR professional as
 *    secretary.
 *  - Art. 6 (and Art. 30(3)): member eligibility.
 *  - Art. 7: two-year term, renewable once.
 *  - Art. 8: quorum (chair plus two members), majority vote, chair breaks a
 *    tie, a dissenting member records the reason.
 */
final class StatutoryCommitteeRules
{
    public const APPOINTED = 'appointed';

    public const ELECTED = 'elected';

    public const HR_REPRESENTATIVE = 'hr_representative';

    public static function applies(GrievanceCommittee $committee): bool
    {
        return in_array($committee->committee_type, [CommitteeType::Disciplinary, CommitteeType::Grievance], true);
    }

    /**
     * Composition problems of the members serving on $on. Codes are
     * translation keys under discipline.committee_rules.
     *
     * @return list<string>
     */
    public function compositionProblems(GrievanceCommittee $committee, ?CarbonInterface $on = null): array
    {
        if (! self::applies($committee)) {
            return [];
        }

        $members = $committee->members()->servingOn($on ?? now())->with('employee:id,gender')->get();
        $bySource = fn (string $source): Collection => $members->filter(fn (GrievanceCommitteeMember $m): bool => $m->selection_source === $source);
        $chairs = $members->filter(fn (GrievanceCommitteeMember $m): bool => $m->isChairperson());
        $elected = $bySource(self::ELECTED);
        $hr = $bySource(self::HR_REPRESENTATIVE);
        $appointed = $bySource(self::APPOINTED);

        $problems = [];
        if ($members->contains(fn (GrievanceCommitteeMember $m): bool => $m->selection_source === null)) {
            $problems[] = 'selection_source_missing';
        }
        if ($chairs->count() !== 1 || $chairs->first()?->selection_source !== self::APPOINTED) {
            $problems[] = 'chair_must_be_appointed';
        }
        if ($hr->count() !== 1 || $hr->first()?->roleEnum()->value !== 'writer') {
            $problems[] = 'hr_secretary_required';
        }

        [$expectedAppointed, $expectedElected] = $committee->committee_type === CommitteeType::Disciplinary ? [2, 2] : [1, 3];
        if ($appointed->count() !== $expectedAppointed) {
            $problems[] = 'appointed_count';
        }
        if ($elected->count() !== $expectedElected) {
            $problems[] = 'elected_count';
        }
        if ($members->count() !== 5) {
            $problems[] = 'member_count';
        }
        if ($elected->isNotEmpty() && ! $elected->contains(fn (GrievanceCommitteeMember $m): bool => strtolower((string) $m->employee?->gender) === 'female')) {
            $problems[] = 'elected_woman_required';
        }

        foreach ($members as $member) {
            if ((int) $member->term_number > 2) {
                $problems[] = 'term_limit_exceeded';
            }
            if ($member->effective_from !== null && $member->effective_to !== null
                && $member->effective_to->gt($member->effective_from->copy()->addYears(2))) {
                $problems[] = 'term_longer_than_two_years';
            }
        }

        return array_values(array_unique($problems));
    }

    /**
     * Art. 6 eligibility of one member. Only facts the system holds are
     * checked; "good conduct and commended performance" (Art. 6(1)) has no
     * authoritative data source and is always reported as needs_data.
     *
     * @return array{eligible: bool|null, problems: list<string>, needs_data: list<string>}
     */
    public function eligibility(GrievanceCommitteeMember $member, ?CarbonInterface $on = null): array
    {
        $on ??= now();
        $problems = [];
        $needsData = ['good_conduct_commendation'];

        $penalised = DisciplinaryDecision::query()
            ->whereHas('disciplinaryCase', fn ($q) => $q->where('accused_employee_id', $member->employee_id))
            ->where('finding', DisciplinaryFinding::Responsible->value)
            ->whereIn('penalty', DisciplinaryPenalty::values())
            ->where('decided_at', '>=', $on->copy()->subYears(2))
            ->exists();
        if ($penalised) {
            $problems[] = 'disciplined_within_two_years';
        }

        // Assignment history may start when the employee was loaded into the
        // system, not when government service began, so a short record is a
        // prompt to verify rather than proof of ineligibility (Art. 6(3)).
        $firstServiceDay = EmployeeAssignment::query()->where('employee_id', $member->employee_id)->min('effective_from');
        if ($firstServiceDay === null || Carbon::parse($firstServiceDay)->gt($on->copy()->subYears(2))) {
            $needsData[] = 'government_service_over_two_years';
        }

        return [
            'eligible' => $problems !== [] ? false : null,
            'problems' => $problems,
            'needs_data' => $needsData,
        ];
    }

    /** Art. 8(2): the chair and at least two other members present. */
    public function quorumMet(int $chairPresent, int $otherMembersPresent): bool
    {
        return $chairPresent === 1 && $otherMembersPresent >= 2;
    }

    /**
     * Art. 8(3): majority wins; on a tie the chair's side prevails.
     *
     * @param  int  $for  votes for the proposed recommendation
     * @param  int  $against  votes against it
     * @return array{adopted: bool, tie_broken_by_chair: bool}
     */
    public function tally(int $for, int $against, bool $chairVotedFor): array
    {
        if ($for === $against) {
            return ['adopted' => $chairVotedFor, 'tie_broken_by_chair' => true];
        }

        return ['adopted' => $for > $against, 'tie_broken_by_chair' => false];
    }
}

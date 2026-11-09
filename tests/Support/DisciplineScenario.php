<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Employee;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\User;
use App\Services\Legal\StatutoryCommitteeRules;
use Modules\Discipline\Contracts\Data\DisciplineRoles;
use Modules\Grievance\Contracts\Data\CommitteeType;
use Modules\Grievance\Domain\Models\GrievanceCommittee;
use Modules\Grievance\Domain\Models\GrievanceCommitteeMember;

/**
 * One institution with an Art. 5 disciplinary committee:
 *   chair + one member appointed by the head, two elected members (one woman),
 *   one HR representative serving as secretary.
 * A second institution holds an out-of-scope case officer.
 */
final class DisciplineScenario
{
    public Organization $institution;

    public Organization $otherInstitution;

    public OrganizationUnit $unit;

    public GrievanceCommittee $committee;

    public User $accused;

    public User $officer;

    public User $head;

    public User $chair;

    public User $appointed;

    public User $electedWoman;

    public User $elected;

    public User $secretary;

    public User $bystander;

    public User $outsider;

    public static function build(): self
    {
        $s = new self;
        $type = OrganizationType::query()->firstOrCreate(['code' => 'DSCT'], ['name_en' => 'Discipline test type']);
        $s->institution = GrievanceScenario::org($type, 'Institution');
        $s->otherInstitution = GrievanceScenario::org($type, 'Other institution');
        $s->unit = GrievanceScenario::unit($s->institution, 'team', 'Records Team');

        $employee = fn (string $gender = 'male'): Employee => tap(
            GrievanceScenario::employee($s->institution, $s->unit),
            fn (Employee $e) => $e->forceFill(['gender' => $gender])->save(),
        );
        $member = [...DisciplineRoles::COMMITTEE_MEMBER_PERMISSIONS];

        $s->accused = GrievanceScenario::user($employee(), DisciplineRoles::EMPLOYEE_PERMISSIONS);
        $s->officer = GrievanceScenario::user($employee(), DisciplineRoles::CASE_OFFICER_PERMISSIONS);
        $s->head = GrievanceScenario::user($employee(), DisciplineRoles::DECISION_AUTHORITY_PERMISSIONS);
        $s->chair = GrievanceScenario::user($employee(), $member);
        $s->appointed = GrievanceScenario::user($employee(), $member);
        $s->electedWoman = GrievanceScenario::user($employee('female'), $member);
        $s->elected = GrievanceScenario::user($employee(), $member);
        $s->secretary = GrievanceScenario::user($employee(), $member);
        $s->bystander = GrievanceScenario::user($employee(), $member);
        $s->outsider = GrievanceScenario::user(GrievanceScenario::employee($s->otherInstitution, null), DisciplineRoles::CASE_OFFICER_PERMISSIONS);

        foreach ([$s->officer, $s->head] as $user) {
            GrievanceScenario::scope($user, $s->institution);
        }
        GrievanceScenario::scope($s->outsider, $s->otherInstitution);

        $s->committee = GrievanceCommittee::query()->create([
            'organization_id' => $s->institution->id,
            'committee_type' => CommitteeType::Disciplinary->value,
            'name_en' => 'Institution Disciplinary Committee',
            'status' => 'active',
            'effective_from' => now()->subYear()->toDateString(),
            'approved_at' => now(),
        ]);

        $seats = [
            [$s->chair, 'chairperson', StatutoryCommitteeRules::APPOINTED],
            [$s->appointed, 'member', StatutoryCommitteeRules::APPOINTED],
            [$s->electedWoman, 'member', StatutoryCommitteeRules::ELECTED],
            [$s->elected, 'member', StatutoryCommitteeRules::ELECTED],
            [$s->secretary, 'writer', StatutoryCommitteeRules::HR_REPRESENTATIVE],
        ];
        foreach ($seats as [$user, $role, $source]) {
            GrievanceCommitteeMember::query()->create([
                'committee_id' => $s->committee->id, 'employee_id' => $user->employee_id, 'role' => $role,
                'selection_source' => $source, 'term_number' => 1,
                'effective_from' => now()->subMonth()->toDateString(),
                'effective_to' => now()->subMonth()->addYears(2)->toDateString(),
                'status' => 'active',
            ]);
        }

        return $s;
    }

    public function accusedEmployee(): Employee
    {
        return Employee::query()->findOrFail($this->accused->employee_id);
    }
}

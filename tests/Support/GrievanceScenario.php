<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\AssignmentStatus;
use App\Enums\CommitteeType;
use App\Enums\OrganizationScopeType;
use App\Enums\OrganizationStatus;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\GrievanceApprovalRule;
use App\Models\GrievanceCategory;
use App\Models\GrievanceCommittee;
use App\Models\GrievanceCommitteeMember;
use App\Models\GrievanceRoute;
use App\Models\GrievanceSlaProfile;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Models\User;
use App\Models\UserOrganizationScope;
use App\Support\Grievances\GrievanceRoles;
use Illuminate\Support\Str;

/**
 * Reference grievance layout, built fresh per test:
 *
 *   Woreda (A)   ── complainant; grievance committee (chair, writer, member)
 *   Sub-city (B) ── Grievance Team (organization unit) with an assigning officer
 *   Bureau (C)   ── Grievance Directorate (organization unit) + head position
 *
 *   A → committee (initial) → B team (timeout) → C directorate (timeout)
 *
 * Every route is explicit and cross-organization; nothing follows the
 * administrative hierarchy.
 */
final class GrievanceScenario
{
    public Organization $woreda;

    public Organization $subcity;

    public Organization $bureau;

    public OrganizationUnit $woredaUnit;

    public OrganizationUnit $team;

    public OrganizationUnit $directorate;

    public Position $headPosition;

    public GrievanceCategory $category;

    public GrievanceCommittee $committee;

    public User $complainant;

    public User $chair;

    public User $writer;

    public User $member;

    public User $teamLead;

    public User $teamOfficer;

    public User $directorateOfficer;

    public User $approver;

    public User $intake;

    public User $outsider;

    public GrievanceSlaProfile $committeeSla;

    public static function build(bool $withRoutes = true, bool $withSla = true): self
    {
        $s = new self;
        $type = OrganizationType::query()->firstOrCreate(['code' => 'GRVT'], ['name_en' => 'Test type']);
        $s->woreda = self::org($type, 'Woreda 01');
        $s->subcity = self::org($type, 'Sub-city');
        $s->bureau = self::org($type, 'Bureau');

        $s->woredaUnit = self::unit($s->woreda, 'team', 'Woreda Office');
        $s->team = self::unit($s->subcity, 'team', 'Grievance Team');
        $s->directorate = self::unit($s->bureau, 'directorate', 'Grievance Directorate');
        $s->headPosition = Position::query()->create(['organization_id' => $s->bureau->id, 'job_position_code' => 'POS-'.Str::upper(Str::random(6)), 'title_en' => 'Bureau Head', 'title_am' => 'የቢሮ ኃላፊ', 'is_active' => true]);

        $s->category = GrievanceCategory::query()->create(['code' => 'CAT-'.Str::random(6), 'name_en' => 'Working conditions', 'name_am' => 'የሥራ ሁኔታ', 'is_active' => true]);

        $s->complainant = self::user(self::employee($s->woreda, $s->woredaUnit), GrievanceRoles::EMPLOYEE_PERMISSIONS);
        $s->chair = self::user(self::employee($s->woreda, $s->woredaUnit), GrievanceRoles::COMMITTEE_CHAIR_PERMISSIONS);
        $s->writer = self::user(self::employee($s->woreda, $s->woredaUnit), GrievanceRoles::COMMITTEE_WRITER_PERMISSIONS);
        $s->member = self::user(self::employee($s->woreda, $s->woredaUnit), GrievanceRoles::COMMITTEE_MEMBER_PERMISSIONS);
        $s->teamLead = self::user(self::employee($s->subcity, $s->team), GrievanceRoles::OFFICER_PERMISSIONS);
        $s->teamOfficer = self::user(self::employee($s->subcity, $s->team), GrievanceRoles::COMMITTEE_MEMBER_PERMISSIONS);
        $s->directorateOfficer = self::user(self::employee($s->bureau, $s->directorate), GrievanceRoles::OFFICER_PERMISSIONS);
        $s->approver = self::user(self::employee($s->bureau, null, $s->headPosition), GrievanceRoles::APPROVER_PERMISSIONS);
        $s->intake = self::user(self::employee($s->woreda, null), ['grievances.intake_review', 'grievances.view_assigned']);
        $s->outsider = self::user(self::employee($s->woreda, $s->woredaUnit), [...GrievanceRoles::OFFICER_PERMISSIONS, ...GrievanceRoles::COMMITTEE_CHAIR_PERMISSIONS]);

        // Scoped users: the intake officer sees the woreda only.
        self::scope($s->intake, $s->woreda);

        $s->committee = GrievanceCommittee::query()->create([
            'organization_id' => $s->woreda->id,
            'committee_type' => CommitteeType::Grievance->value,
            'name_en' => 'Woreda 01 Grievance Committee',
            'status' => 'active',
            'effective_from' => now()->subYear()->toDateString(),
            'approved_at' => now(),
        ]);
        foreach (['chairperson' => $s->chair, 'writer' => $s->writer, 'member' => $s->member] as $role => $user) {
            GrievanceCommitteeMember::query()->create([
                'committee_id' => $s->committee->id, 'employee_id' => $user->employee_id, 'role' => $role,
                'effective_from' => now()->subMonth()->toDateString(), 'status' => 'active',
            ]);
        }

        if ($withRoutes) {
            self::route('organization', $s->woreda->id, 'committee', $s->committee->id, 'initial_assignment');
            self::route('committee', $s->committee->id, 'organization_unit', $s->team->id, 'timeout_escalation');
            self::route('organization_unit', $s->team->id, 'organization_unit', $s->directorate->id, 'timeout_escalation');
        }

        if ($withSla) {
            // Deactivate the seeded default so tests control deadlines exactly.
            GrievanceSlaProfile::query()->update(['is_active' => false]);
            $s->committeeSla = GrievanceSlaProfile::query()->create([
                'name_en' => 'Committee 3 working days', 'purpose' => 'resolution', 'handler_type' => 'committee',
                'resolution_days' => 3, 'day_type' => 'working_days', 'start_point' => 'on_assignment',
                'auto_escalate' => true, 'priority' => 1, 'effective_from' => now()->subYear()->toDateString(), 'is_active' => true,
            ]);
            GrievanceSlaProfile::query()->create([
                'name_en' => 'Team 10 working days', 'purpose' => 'resolution', 'handler_type' => 'organization_unit',
                'resolution_days' => 10, 'day_type' => 'working_days', 'start_point' => 'on_assignment',
                'auto_escalate' => true, 'priority' => 1, 'effective_from' => now()->subYear()->toDateString(), 'is_active' => true,
            ]);
        }

        return $s;
    }

    /** Directorate decisions need the Bureau Head's approval. */
    public function requireDirectorateApproval(): GrievanceApprovalRule
    {
        return GrievanceApprovalRule::query()->create([
            'name_en' => 'Directorate decisions', 'handler_type' => 'organization_unit', 'handler_id' => $this->directorate->id,
            'requires_approval' => true, 'approver_position_id' => $this->headPosition->id, 'priority' => 1,
            'effective_from' => now()->subYear()->toDateString(), 'is_active' => true,
        ]);
    }

    public static function route(string $sourceType, string $sourceId, string $targetType, string $targetId, string $movement, bool $approved = true, bool $descendants = false): GrievanceRoute
    {
        return GrievanceRoute::query()->create([
            'source_handler_type' => $sourceType, 'source_handler_id' => $sourceId, 'include_descendants' => $descendants,
            'target_handler_type' => $targetType, 'target_handler_id' => $targetId, 'movement_type' => $movement,
            'priority' => 10, 'effective_from' => now()->subYear()->toDateString(), 'is_active' => true,
            'approved_at' => $approved ? now() : null,
        ]);
    }

    public static function org(OrganizationType $type, string $name): Organization
    {
        return Organization::query()->create([
            'organization_type_id' => $type->id, 'code' => 'GRV-'.Str::upper(Str::random(8)),
            'name_en' => $name, 'name_am' => $name.' (አማ)', 'status' => OrganizationStatus::Active,
        ]);
    }

    public static function unit(Organization $org, string $type, string $name, ?OrganizationUnit $parent = null): OrganizationUnit
    {
        return OrganizationUnit::query()->create([
            'organization_id' => $org->id, 'parent_unit_id' => $parent?->id, 'unit_type' => $type,
            'code' => 'U-'.Str::upper(Str::random(6)), 'name_en' => $name, 'name_am' => $name.' (አማ)', 'status' => 'active',
        ]);
    }

    public static function employee(Organization $org, ?OrganizationUnit $unit, ?Position $position = null): Employee
    {
        $uid = Str::upper(Str::random(8));
        $employee = Employee::query()->create([
            'employee_number' => 'EMP-'.$uid, 'first_name' => 'Test', 'last_name' => $uid,
            'full_name' => 'ሠራተኛ '.$uid, 'name_en' => 'Employee '.$uid, 'status' => 'active', 'phone' => '0911'.random_int(100000, 999999),
        ]);
        $assignment = EmployeeAssignment::query()->create([
            'employee_id' => $employee->id, 'organization_id' => $org->id, 'organization_unit_id' => $unit?->id,
            'position_id' => $position?->id, 'assignment_status' => AssignmentStatus::Active,
            'effective_from' => now()->subYear()->toDateString(), 'is_current' => true,
        ]);
        $employee->forceFill(['current_assignment_id' => $assignment->id])->save();

        return $employee;
    }

    /** @param  list<string>  $permissions */
    public static function user(Employee $employee, array $permissions): User
    {
        $user = User::factory()->create(['email' => 'g-'.Str::lower(Str::random(10)).'@test.local']);
        $user->forceFill(['employee_id' => $employee->id])->save();
        $user->givePermissionTo(array_values(array_unique($permissions)));

        return $user->refresh();
    }

    public static function scope(User $user, Organization $org): void
    {
        UserOrganizationScope::query()->create([
            'user_id' => $user->id, 'organization_id' => $org->id, 'scope_type' => OrganizationScopeType::Self,
            'is_active' => true, 'effective_from' => now()->subYear()->toDateString(),
        ]);
    }
}

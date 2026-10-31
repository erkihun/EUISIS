<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\AssignmentStatus;
use App\Enums\EmployeeStatus;
use App\Enums\HierarchyVersionStatus;
use App\Enums\OrganizationRelationshipType;
use App\Enums\OrganizationScopeType;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\HierarchyVersion;
use App\Models\Organization;
use App\Models\OrganizationClosurePath;
use App\Models\OrganizationEdge;
use App\Models\OrganizationType;
use App\Models\Permission;
use App\Models\Position;
use App\Models\User;
use App\Models\UserOrganizationScope;
use App\Services\SituationRoom\SituationPermissions;
use Illuminate\Support\Facades\Cache;
use Modules\Feedback\Domain\Models\EmployeeServiceFeedback;

/**
 * The Situation Room fixture: a published hierarchy
 *
 *   Bureau
 *   ├── Subcity A ── Woreda A1, Woreda A2
 *   └── Subcity B ── Woreda B1
 *
 * with a known number of people and public submissions in each
 * organization, so every roll-up has an exact expected value.
 */
final class SituationRoomScenario
{
    /** People employed directly in each organization: [men, women]. */
    public const PEOPLE = [
        'bureau' => [1, 1],
        'subcity_a' => [1, 0],
        'woreda_a1' => [2, 1],
        'woreda_a2' => [0, 2],
        'subcity_b' => [1, 1],
        'woreda_b1' => [3, 2],
    ];

    /** Complaints received this month in each organization. */
    public const COMPLAINTS = [
        'bureau' => 0,
        'subcity_a' => 1,
        'woreda_a1' => 2,
        'woreda_a2' => 3,
        'subcity_b' => 0,
        'woreda_b1' => 4,
    ];

    public HierarchyVersion $version;

    /** @var array<string, Organization> */
    public array $org = [];

    /** @var array<string, list<Employee>> */
    public array $people = [];

    private int $sequence = 0;

    public static function build(): self
    {
        Cache::flush();
        foreach ([...SituationPermissions::all(), SituationPermissions::EMPLOYEE_TARGET] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $s = new self;
        $type = OrganizationType::query()->firstOrCreate(['code' => 'SR-TYPE'], ['name_en' => 'Office', 'name_am' => 'ጽሕፈት ቤት']);
        $s->version = HierarchyVersion::query()->create(['version_name' => 'sr-published', 'status' => HierarchyVersionStatus::Published, 'effective_from' => now()->subYear()->toDateString(), 'approval_date' => now()->subYear()]);

        foreach (['bureau' => 'Bureau', 'subcity_a' => 'Subcity A', 'woreda_a1' => 'Woreda A1', 'woreda_a2' => 'Woreda A2', 'subcity_b' => 'Subcity B', 'woreda_b1' => 'Woreda B1'] as $key => $name) {
            $s->org[$key] = Organization::query()->create([
                'organization_type_id' => $type->id, 'code' => 'SR-'.strtoupper($key), 'name_en' => $name, 'name_am' => $name.' (am)',
                'status' => 'active', 'effective_from' => now()->subYear()->toDateString(),
            ]);
            OrganizationClosurePath::query()->create(['hierarchy_version_id' => $s->version->id, 'ancestor_organization_id' => $s->org[$key]->id, 'descendant_organization_id' => $s->org[$key]->id, 'depth' => 0]);
        }

        $s->attach('bureau', 'subcity_a');
        $s->attach('bureau', 'subcity_b');
        $s->attach('subcity_a', 'woreda_a1');
        $s->attach('subcity_a', 'woreda_a2');
        $s->attach('subcity_b', 'woreda_b1');

        foreach (self::PEOPLE as $key => [$men, $women]) {
            $position = Position::query()->create(['organization_id' => $s->org[$key]->id, 'job_position_code' => 'SR-P-'.$key, 'title_en' => 'Officer '.$key, 'is_active' => true]);
            foreach (array_merge(array_fill(0, $men, 'male'), array_fill(0, $women, 'female')) as $gender) {
                $s->people[$key][] = $s->employee($s->org[$key], $position, $gender);
            }
        }

        foreach (self::COMPLAINTS as $key => $count) {
            for ($i = 0; $i < $count; $i++) {
                $s->submission($s->org[$key], 'complaint');
            }
        }

        return $s;
    }

    public function employee(Organization $organization, ?Position $position, string $gender, ?string $dob = '1990-01-01'): Employee
    {
        $this->sequence++;
        $employee = Employee::query()->create([
            'employee_number' => 'SR-E-'.$this->sequence,
            'first_name' => 'Person',
            'last_name' => (string) $this->sequence,
            'full_name' => 'Person '.str_pad((string) $this->sequence, 3, '0', STR_PAD_LEFT),
            'gender' => $gender,
            'date_of_birth' => $dob,
            'employment_type' => 'permanent',
            'status' => EmployeeStatus::Active->value,
        ]);
        $assignment = EmployeeAssignment::query()->create([
            'employee_id' => $employee->id, 'organization_id' => $organization->id, 'position_id' => $position?->id,
            'is_current' => true, 'assignment_status' => AssignmentStatus::Active->value, 'effective_from' => now()->subYear()->toDateString(),
        ]);
        $employee->forceFill(['current_assignment_id' => $assignment->id])->save();

        return $employee;
    }

    /** @param  array<string, mixed>  $extra */
    public function submission(Organization $organization, string $type, array $extra = []): EmployeeServiceFeedback
    {
        return EmployeeServiceFeedback::query()->create($extra + [
            'type' => $type,
            'target_type' => 'organization',
            'source_type' => 'organization_feedback_qr',
            'organization_target_id' => $organization->id,
            'organization_id' => $organization->id,
            'status' => 'pending',
            'comment' => 'Private narrative that must never reach the Situation Room',
            'client_name' => 'Private Submitter',
            'client_contact' => '+251911000000',
            'submitted_at' => now()->startOfMonth()->addHours(10),
            'tip_type' => $type === 'suggestion' ? 'CORRUPTION' : null,
            'rating' => $type === 'feedback' ? 4 : null,
        ]);
    }

    /** A viewer with the given grants and organization scope (null: unrestricted). */
    public function viewer(array $permissions, ?string $orgKey = null, OrganizationScopeType $scope = OrganizationScopeType::Subtree): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo([SituationPermissions::VIEW, ...$permissions]);

        if ($orgKey !== null) {
            UserOrganizationScope::query()->create([
                'user_id' => $user->id, 'organization_id' => $this->org[$orgKey]->id, 'scope_type' => $scope,
                'is_active' => true, 'effective_from' => now()->subDay()->toDateString(),
            ]);
        }

        return $user->fresh();
    }

    /** Expected headcount of an organization and everything below it. */
    public function headcount(string ...$keys): int
    {
        return array_sum(array_map(fn (string $k): int => array_sum(self::PEOPLE[$k]), $keys));
    }

    private function attach(string $parent, string $child): void
    {
        OrganizationEdge::query()->create([
            'hierarchy_version_id' => $this->version->id, 'parent_organization_id' => $this->org[$parent]->id, 'child_organization_id' => $this->org[$child]->id,
            'relationship_type' => OrganizationRelationshipType::ReportsTo, 'effective_from' => now()->subYear()->toDateString(),
        ]);
        $ancestors = OrganizationClosurePath::query()->where('hierarchy_version_id', $this->version->id)->where('descendant_organization_id', $this->org[$parent]->id)->get();
        foreach ($ancestors as $path) {
            OrganizationClosurePath::query()->create(['hierarchy_version_id' => $this->version->id, 'ancestor_organization_id' => $path->ancestor_organization_id, 'descendant_organization_id' => $this->org[$child]->id, 'depth' => $path->depth + 1]);
        }
    }
}

<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Actions\EmployeeVerification;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\EmployeeDocument;
use App\Models\HierarchyVersion;
use App\Models\IdCard;
use App\Models\Organization;
use App\Models\OrganizationEdge;
use App\Models\OrganizationType;
use App\Models\Position;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/**
 * A small city: the city office with two bureaus under it (structural edges of
 * the published hierarchy), a position in each bureau, and one placed employee
 * with an active card in the first bureau.
 */
trait BuildsGoldenPath
{
    protected Organization $city;

    protected Organization $bureauA;

    protected Organization $bureauB;

    protected Position $positionA;

    protected Position $positionB;

    protected Employee $employee;

    protected IdCard $card;

    protected function buildGoldenPath(): void
    {
        $type = OrganizationType::query()->create(['code' => 'BUREAU', 'name_en' => 'Bureau']);
        $this->city = $this->organization($type, 'CITY');
        $this->bureauA = $this->organization($type, 'BUR-A');
        $this->bureauB = $this->organization($type, 'BUR-B');

        $version = HierarchyVersion::query()->create(['version_name' => 'v1', 'status' => HierarchyVersion::PUBLISHED]);
        foreach ([$this->bureauA, $this->bureauB] as $child) {
            OrganizationEdge::query()->create([
                'hierarchy_version_id' => $version->id,
                'parent_organization_id' => $this->city->id,
                'child_organization_id' => $child->id,
                'relationship_type' => OrganizationEdge::STRUCTURAL,
            ]);
        }

        $this->positionA = $this->position($this->bureauA, 'POS-A');
        $this->positionB = $this->position($this->bureauB, 'POS-B');
        $this->employee = $this->placedEmployee('EMP-0001', $this->positionA, '2024-01-01');
        $this->card = IdCard::query()->create([
            'employee_id' => $this->employee->id,
            'card_number' => 'CARD-0001',
            'status' => IdCard::ACTIVE,
            'is_current' => true,
            'public_card_uuid' => (string) Str::uuid(),
            'issued_at' => now()->subMonth(),
        ]);
    }

    protected function organization(OrganizationType $type, string $code): Organization
    {
        return Organization::query()->create([
            'organization_type_id' => $type->id,
            'code' => $code,
            'name_en' => $code.' office',
            'status' => Organization::ACTIVE,
        ]);
    }

    protected function position(Organization $organization, string $code): Position
    {
        return Position::query()->create([
            'organization_id' => $organization->id,
            'job_position_code' => $code,
            'title_en' => $code.' officer',
            'is_active' => true,
        ]);
    }

    protected function placedEmployee(string $number, Position $position, string $from): Employee
    {
        $employee = Employee::query()->create([
            'employee_number' => $number,
            'first_name' => 'Abebe',
            'last_name' => $number,
            'full_name' => 'Abebe '.$number,
            'status' => Employee::ACTIVE,
        ]);
        $assignment = EmployeeAssignment::query()->create([
            'employee_id' => $employee->id,
            'organization_id' => $position->organization_id,
            'position_id' => $position->id,
            'assignment_status' => EmployeeAssignment::ACTIVE,
            'is_current' => true,
            'effective_from' => $from,
        ]);
        $employee->forceFill(['current_assignment_id' => $assignment->id])->save();
        // The EUISIS source database of the import tests has no evidence table.
        if ($employee->getConnection()->getSchemaBuilder()->hasTable('employee_documents')) {
            $this->giveVerifiedPhoto($employee->id);
        }

        return $employee->fresh();
    }

    /**
     * A verified current photo on record (ADR 0009), as a card needs by default.
     * Only the evidence row: eligibility reads the record, not the file.
     */
    protected function giveVerifiedPhoto(string $employeeId): EmployeeDocument
    {
        $id = (string) Str::uuid7();
        $photo = new EmployeeDocument;
        $photo->forceFill([
            'id' => $id, 'employee_id' => $employeeId, 'kind' => EmployeeDocument::PHOTO, 'document_type' => EmployeeDocument::PHOTO,
            'lineage_id' => $id, 'version' => 1, 'is_current' => true, 'status' => EmployeeDocument::VERIFIED,
            'storage_disk' => 'evidence', 'storage_path' => "{$employeeId}/photo/{$id}.jpg", 'original_filename' => 'photo.jpg',
            'mime_type' => 'image/jpeg', 'file_size' => 1, 'checksum' => hash('sha256', $id), 'width' => 400, 'height' => 500,
            'uploaded_by' => 1, 'verified_by' => 2, 'verified_at' => now(),
        ])->save();

        return $photo;
    }

    /**
     * Takes a registered (draft) employee through the verified-employee stage with
     * three different people, as the separation of duties requires (ADR 0008).
     */
    protected function approveEmployee(string $employeeId): Employee
    {
        $person = fn (string $role): User => User::query()->create([
            'name' => $role, 'email' => $role.'-'.Str::random(6).'@core.test', 'password' => 'secret-password',
            'role' => $role, 'scope_type' => User::SCOPE_CITYWIDE, 'is_active' => true,
        ]);
        $verification = app(EmployeeVerification::class);
        $employee = Employee::query()->findOrFail($employeeId);
        $verification->submit($employee, $person('core_officer'));
        $verification->verify($employee, $person('core_officer'), array_fill_keys(array_keys($verification->checklist($employee)), true));

        return $verification->approve($employee, $person('core_admin'));
    }

    protected function actingAsCore(string $role = 'core_viewer', ?Organization $scope = null, bool $active = true): User
    {
        $user = User::query()->create([
            'name' => $role,
            'email' => $role.'-'.Str::random(6).'@core.test',
            'password' => 'secret-password',
            'role' => $role,
            'scope_organization_id' => $scope?->id,
            // Explicit scope (ADR 0008): an organization and everything under it, or the whole city.
            'scope_type' => $scope === null ? User::SCOPE_CITYWIDE : User::SCOPE_SUBTREE,
            'is_active' => $active,
        ]);
        Sanctum::actingAs($user);

        return $user;
    }
}

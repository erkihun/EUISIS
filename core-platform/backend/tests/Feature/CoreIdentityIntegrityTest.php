<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\SaveOrganization;
use App\Models\EmployeeAssignment;
use App\Models\HierarchyVersion;
use App\Models\OrganizationEdge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsGoldenPath;
use Tests\TestCase;

class CoreIdentityIntegrityTest extends TestCase
{
    use BuildsGoldenPath, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildGoldenPath();
    }

    public function test_editing_an_organization_without_a_parent_field_preserves_its_edge(): void
    {
        $actor = $this->actingAsCore('core_officer');
        $edge = OrganizationEdge::query()->where('child_organization_id', $this->bureauA->id)->sole();

        app(SaveOrganization::class)->execute($this->bureauA, ['name_en' => 'Renamed bureau'], $actor);

        $this->assertDatabaseHas('organization_edges', ['id' => $edge->id, 'parent_organization_id' => $this->city->id]);
        $this->assertSame('Renamed bureau', $this->bureauA->fresh()->name_en);
    }

    public function test_an_explicit_null_parent_still_detaches_an_organization(): void
    {
        $actor = $this->actingAsCore('core_officer');

        $before = HierarchyVersion::current();

        app(SaveOrganization::class)->execute($this->bureauA, ['parent_organization_id' => null], $actor);

        $current = HierarchyVersion::current();
        $this->assertNotSame($before->id, $current->id);
        $this->assertFalse(OrganizationEdge::query()->where('hierarchy_version_id', $current->id)->where('child_organization_id', $this->bureauA->id)->exists());
        // The structure in force before the change is history, not rewritten.
        $this->assertSame('archived', $before->fresh()->status);
        $this->assertTrue(OrganizationEdge::query()->where('hierarchy_version_id', $before->id)->where('child_organization_id', $this->bureauA->id)->exists());
    }

    public function test_a_transfer_cannot_close_another_employees_assignment(): void
    {
        $this->actingAsCore('core_officer');
        $other = $this->placedEmployee('EMP-OTHER', $this->positionB, '2024-01-01');
        $otherAssignment = $other->current_assignment_id;
        $this->employee->update(['current_assignment_id' => $otherAssignment]);

        $this->postJson('/api/v1/employees/'.$this->employee->id.'/transfer', [
            'position_id' => $this->positionA->id,
            'effective_date' => '2025-03-01',
        ])->assertUnprocessable()->assertJsonValidationErrors('employee');

        $this->assertDatabaseHas('employee_assignments', ['id' => $otherAssignment, 'assignment_status' => EmployeeAssignment::ACTIVE, 'is_current' => true]);
    }

    public function test_registration_rejects_a_position_in_an_inactive_organization(): void
    {
        $this->actingAsCore('core_officer');
        $this->bureauB->update(['status' => 'inactive']);

        $this->postJson('/api/v1/employees', [
            'employee_number' => 'EMP-NEW', 'first_name' => 'New', 'last_name' => 'Employee',
            'position_id' => $this->positionB->id, 'effective_from' => '2025-01-01',
        ])->assertUnprocessable()->assertJsonValidationErrors('position_id');

        $this->assertDatabaseMissing('employees', ['employee_number' => 'EMP-NEW']);
    }

    public function test_transfer_rejects_a_position_in_an_inactive_organization(): void
    {
        $this->actingAsCore('core_officer');
        $this->bureauB->update(['status' => 'inactive']);
        $original = $this->employee->current_assignment_id;

        $this->postJson('/api/v1/employees/'.$this->employee->id.'/transfer', [
            'position_id' => $this->positionB->id, 'effective_date' => '2025-03-01',
        ])->assertUnprocessable()->assertJsonValidationErrors('position_id');

        $this->assertSame($original, $this->employee->fresh()->current_assignment_id);
        $this->assertDatabaseHas('employee_assignments', ['id' => $original, 'assignment_status' => EmployeeAssignment::ACTIVE]);
    }
}

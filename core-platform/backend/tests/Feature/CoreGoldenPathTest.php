<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CardRequest;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\HierarchyVersion;
use App\Models\IdCard;
use App\Models\Organization;
use App\Models\OrganizationEdge;
use App\Models\OrganizationUnitType;
use App\Models\Position;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsGoldenPath;
use Tests\TestCase;

/** The whole Golden Path through the Core API: structure, positions, employment, cards, checks. */
class CoreGoldenPathTest extends TestCase
{
    use BuildsGoldenPath;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildGoldenPath();
    }

    public function test_an_organization_is_created_under_a_parent_with_a_generated_code(): void
    {
        $this->actingAsCore('core_officer');

        $id = $this->postJson('/api/v1/organizations', [
            'organization_type_id' => $this->city->organization_type_id,
            'name_en' => 'Woreda 1',
            'parent_organization_id' => $this->bureauA->id,
        ])->assertCreated()->assertJsonPath('data.code', 'ORG-00001')->json('data.id');

        $this->assertTrue(OrganizationEdge::query()->where('child_organization_id', $id)->where('parent_organization_id', $this->bureauA->id)->exists());
        $this->getJson('/api/v1/organizations/'.$this->bureauA->id)->assertOk()->assertJsonPath('data.children.0.id', $id)->assertJsonPath('data.parent.id', $this->city->id);
    }

    public function test_an_organization_cannot_move_under_its_own_descendant(): void
    {
        $this->actingAsCore('core_admin');

        $this->putJson('/api/v1/organizations/'.$this->city->id, ['parent_organization_id' => $this->bureauA->id])
            ->assertUnprocessable()->assertJsonValidationErrors('parent_organization_id');
    }

    public function test_a_scoped_officer_works_only_inside_the_scope(): void
    {
        $this->actingAsCore('core_officer', $this->bureauA);

        $this->postJson('/api/v1/organizations', ['organization_type_id' => $this->city->organization_type_id, 'name_en' => 'X', 'parent_organization_id' => $this->bureauB->id])
            ->assertUnprocessable();
        $this->postJson('/api/v1/positions', ['organization_id' => $this->bureauB->id, 'title_en' => 'Clerk'])->assertUnprocessable();
        $this->postJson('/api/v1/positions', ['organization_id' => $this->bureauA->id, 'title_en' => 'Clerk'])->assertCreated();
    }

    public function test_units_and_positions_belong_to_their_organization(): void
    {
        $this->actingAsCore('core_officer');
        $type = OrganizationUnitType::query()->create(['code' => 'directorate', 'name_en' => 'Directorate']);

        $unitId = $this->postJson('/api/v1/organization-units', ['organization_id' => $this->bureauA->id, 'organization_unit_type_id' => $type->id, 'name_en' => 'Finance'])
            ->assertCreated()->assertJsonPath('data.unit_type', 'directorate')->json('data.id');
        $this->postJson('/api/v1/positions', ['organization_id' => $this->bureauB->id, 'organization_unit_id' => $unitId, 'title_en' => 'Clerk'])
            ->assertUnprocessable()->assertJsonValidationErrors('organization_unit_id');
        $this->postJson('/api/v1/positions', ['organization_unit_id' => $unitId, 'title_en' => 'Clerk'])
            ->assertCreated()->assertJsonPath('data.organization_id', $this->bureauA->id)->assertJsonPath('data.job_position_code', 'POS-000001');
    }

    public function test_a_held_position_cannot_be_retired_but_an_establishment_adds_seats(): void
    {
        $this->actingAsCore('core_officer');

        $this->putJson('/api/v1/positions/'.$this->positionA->id, ['is_active' => false])->assertUnprocessable()->assertJsonValidationErrors('is_active');

        $this->postJson('/api/v1/positions/'.$this->positionA->id.'/establishments', ['approved_slots' => 3, 'effective_from' => now()->subDay()->toDateString()])->assertCreated();
        $this->getJson('/api/v1/positions/'.$this->positionA->id)->assertOk()->assertJsonPath('data.capacity', 3)->assertJsonCount(1, 'data.holders');
    }

    public function test_ending_employment_closes_the_placement_and_suspends_the_card(): void
    {
        $this->actingAsCore('core_officer');

        $this->postJson('/api/v1/employees/'.$this->employee->id.'/status', ['status' => 'retired', 'reason' => 'Reached retirement age'])->assertOk();

        $placement = EmployeeAssignment::query()->findOrFail($this->employee->current_assignment_id);
        $this->assertSame(EmployeeAssignment::CLOSED, $placement->assignment_status);
        $this->assertSame(now()->toDateString(), $placement->effective_to->toDateString());
        $this->assertSame(IdCard::SUSPENDED, $this->card->fresh()->status);
        $this->assertSame(0, Position::query()->findOrFail($this->positionA->id)->holders()->count());

        $this->postJson('/api/v1/employees/'.$this->employee->id.'/status', ['status' => 'active', 'reason' => 'Retirement was recorded in error'])->assertOk();
        $this->assertSame(EmployeeAssignment::ACTIVE, $placement->fresh()->assignment_status);
    }

    public function test_the_employee_number_never_changes(): void
    {
        $this->actingAsCore('core_officer');

        $this->putJson('/api/v1/employees/'.$this->employee->id, ['employee_number' => 'NEW-1', 'first_name' => 'Kebede'])
            ->assertOk()->assertJsonPath('data.employee_number', 'EMP-0001')->assertJsonPath('data.full_name', 'Kebede EMP-0001');
    }

    public function test_a_card_goes_from_request_to_active_with_two_people(): void
    {
        $this->card->update(['status' => IdCard::REVOKED, 'is_current' => false]);
        $requester = $this->actingAsCore('core_officer');
        $requestId = $this->postJson('/api/v1/employees/'.$this->employee->id.'/card-requests', ['request_type' => 'new'])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/employees/'.$this->employee->id.'/card-requests', ['request_type' => 'new'])->assertUnprocessable();

        $this->postJson('/api/v1/card-requests/'.$requestId.'/approve')->assertUnprocessable();

        $this->actingAsCore('core_admin');
        $cardId = $this->postJson('/api/v1/card-requests/'.$requestId.'/approve')->assertOk()
            ->assertJsonPath('data.status', IdCard::PENDING_PRINT)
            ->assertJsonPath('data.card_number', 'CARD-'.now()->format('Y').'-000001')
            ->json('data.id');
        $this->assertSame(CardRequest::APPROVED, CardRequest::query()->findOrFail($requestId)->status);
        $this->assertNotSame($requester->id, CardRequest::query()->findOrFail($requestId)->approved_by);

        $this->postJson("/api/v1/id-cards/{$cardId}/print")->assertOk()->assertJsonPath('data.status', IdCard::PRINTED);
        $this->postJson("/api/v1/id-cards/{$cardId}/issue")->assertUnprocessable();
        $admin = auth()->user();
        $this->actingAsCore('core_officer');
        $this->postJson("/api/v1/id-cards/{$cardId}/quality-check", ['result' => 'passed'])->assertOk();
        Sanctum::actingAs($admin);
        foreach (['issue' => IdCard::ISSUED, 'activate' => IdCard::ACTIVE] as $step => $status) {
            $this->postJson("/api/v1/id-cards/{$cardId}/{$step}")->assertOk()->assertJsonPath('data.status', $status);
        }
        $this->postJson('/api/v1/cards/verify', ['card_uuid' => IdCard::query()->findOrFail($cardId)->public_card_uuid])->assertJsonPath('valid', true);
    }

    public function test_a_lost_card_is_replaced_and_linked(): void
    {
        $this->actingAsCore('core_officer');
        $this->postJson('/api/v1/id-cards/'.$this->card->id.'/report_lost', ['reason' => 'Lost on the bus'])->assertOk()->assertJsonPath('data.status', IdCard::LOST);
        $requestId = $this->postJson('/api/v1/id-cards/'.$this->card->id.'/replace', ['reason' => 'Lost on the bus'])->assertCreated()
            ->assertJsonPath('data.request_type', 'lost')->json('data.id');
        $this->assertSame(IdCard::REPLACED, $this->card->fresh()->status);

        $this->actingAsCore('core_admin');
        $newId = $this->postJson('/api/v1/card-requests/'.$requestId.'/approve')->assertOk()->json('data.id');

        $this->assertSame($this->card->id, IdCard::query()->findOrFail($newId)->previous_card_id);
        $this->assertDatabaseHas('card_replacements', ['old_card_id' => $this->card->id, 'new_card_id' => $newId]);
        $this->getJson('/api/v1/data-quality')->assertOk()->assertJsonPath('checks.4.key', 'replacements_unlinked')->assertJsonPath('checks.4.count', 0);
    }

    public function test_a_request_can_be_rejected_with_a_reason(): void
    {
        $this->card->update(['status' => IdCard::REVOKED, 'is_current' => false]);
        $this->actingAsCore('core_officer');
        $requestId = $this->postJson('/api/v1/employees/'.$this->employee->id.'/card-requests', ['request_type' => 'new'])->json('data.id');

        $this->postJson('/api/v1/card-requests/'.$requestId.'/reject', ['reason' => 'Photo missing from file'])->assertOk()->assertJsonPath('data.status', CardRequest::REJECTED);
        $this->getJson('/api/v1/card-requests?status=pending')->assertOk()->assertJsonPath('total', 0);
    }

    public function test_the_data_quality_checks_find_a_separated_employee_still_placed(): void
    {
        Employee::query()->whereKey($this->employee->id)->update(['status' => 'terminated']);
        $this->actingAsCore('core_admin');

        $checks = collect($this->getJson('/api/v1/data-quality')->assertOk()->json('checks'))->keyBy('key');

        $this->assertSame(1, $checks['separated_still_placed']['count']);
        $this->assertSame([$this->employee->id], $checks['separated_still_placed']['samples']);
        $this->assertSame(1, $checks['separated_card_active']['count']);
    }

    public function test_only_an_administrator_manages_accounts_and_gets_a_one_time_password(): void
    {
        $this->actingAsCore('core_officer');
        $this->postJson('/api/v1/users', ['name' => 'Hana', 'email' => 'hana@core.test', 'role' => 'core_viewer'])->assertForbidden();

        $admin = $this->actingAsCore('core_admin');
        $response = $this->postJson('/api/v1/users', ['name' => 'Hana', 'email' => 'hana@core.test', 'role' => 'core_viewer', 'scope_organization_id' => $this->bureauA->id])->assertCreated();
        $this->assertSame(16, strlen($response->json('one_time_password')));

        $this->putJson('/api/v1/users/'.$admin->id, ['name' => $admin->name, 'email' => $admin->email, 'role' => 'core_viewer'])->assertUnprocessable();
    }

    public function test_the_citys_reports_to_edges_make_the_tree_and_the_scope(): void
    {
        $woreda = $this->organization($this->city->type()->firstOrFail(), 'WOREDA-1');
        OrganizationEdge::query()->create([
            'hierarchy_version_id' => HierarchyVersion::current()->id,
            'parent_organization_id' => $this->bureauA->id,
            'child_organization_id' => $woreda->id,
            'relationship_type' => 'reports_to',
        ]);
        OrganizationEdge::query()->create([
            'hierarchy_version_id' => HierarchyVersion::current()->id,
            'parent_organization_id' => $this->bureauB->id,
            'child_organization_id' => $woreda->id,
            'relationship_type' => 'oversight',
        ]);

        $this->actingAsCore('core_viewer', $this->bureauA);
        $this->getJson('/api/v1/organizations')->assertOk()->assertJsonPath('total', 2);

        $this->actingAsCore('core_viewer', $this->bureauB);
        $this->getJson('/api/v1/organizations')->assertOk()->assertJsonPath('total', 1);
    }

    public function test_the_hierarchy_tree_is_cut_to_the_scope(): void
    {
        $this->actingAsCore('core_viewer', $this->bureauA);

        $this->getJson('/api/v1/hierarchy')->assertOk()->assertJsonCount(1, 'nodes')->assertJsonPath('nodes.0.parent_id', null);

        $this->actingAsCore('core_viewer');
        $nodes = collect($this->getJson('/api/v1/hierarchy')->json('nodes'))->keyBy('id');
        $this->assertSame($this->city->id, $nodes[$this->bureauB->id]['parent_id']);
        $this->assertNotNull(HierarchyVersion::current());
        $this->assertSame(3, Organization::query()->count());
    }
}

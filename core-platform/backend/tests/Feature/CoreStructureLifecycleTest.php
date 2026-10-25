<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\HierarchyVersion;
use App\Models\Organization;
use App\Models\OrganizationEdge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsGoldenPath;
use Tests\TestCase;

/** Organization archive and delete rules, and the governed hierarchy version lifecycle with its history. */
class CoreStructureLifecycleTest extends TestCase
{
    use BuildsGoldenPath, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildGoldenPath();
    }

    public function test_an_organization_with_people_and_positions_cannot_leave_service_and_says_why(): void
    {
        $this->actingAsCore('core_officer');

        $response = $this->putJson('/api/v1/organizations/'.$this->bureauA->id, ['status' => 'archived'])->assertUnprocessable();
        $messages = implode(' ', $response->json('errors.status'));
        $this->assertStringContainsString('employee(s) are placed here', $messages);
        $this->assertStringContainsString('position(s) are active', $messages);
        $this->assertSame(Organization::ACTIVE, $this->bureauA->fresh()->status);

        $this->getJson('/api/v1/organizations/'.$this->bureauA->id.'/usage')->assertOk()
            ->assertJsonStructure(['deactivation_blockers' => ['current_placements', 'active_positions'], 'deletion_blockers' => ['hierarchy_history', 'positions', 'assignments']]);
    }

    public function test_the_city_office_cannot_close_while_bureaus_under_it_are_in_service(): void
    {
        $this->actingAsCore('core_admin');

        $this->putJson('/api/v1/organizations/'.$this->city->id, ['status' => 'inactive'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    public function test_an_unused_organization_is_deleted_with_a_reason_and_history_blocks_deletion(): void
    {
        $this->actingAsCore('core_admin');
        $id = $this->postJson('/api/v1/organizations', ['organization_type_id' => $this->city->organization_type_id, 'name_en' => 'Typo office', 'status' => 'draft'])
            ->assertCreated()->json('data.id');

        $this->deleteJson('/api/v1/organizations/'.$id, [])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->deleteJson('/api/v1/organizations/'.$id, ['reason' => 'Created by mistake'])->assertOk();
        $this->assertSoftDeleted('organizations', ['id' => $id]);
        $this->assertDatabaseHas('core_audit_logs', ['action' => 'organization.deleted', 'subject_id' => $id, 'reason' => 'Created by mistake']);
        $this->getJson('/api/v1/organizations/'.$id)->assertNotFound();

        $this->deleteJson('/api/v1/organizations/'.$this->bureauB->id, ['reason' => 'Not needed any more'])
            ->assertUnprocessable()->assertJsonValidationErrors('organization');
        $this->assertNotSoftDeleted('organizations', ['id' => $this->bureauB->id]);
    }

    public function test_a_hierarchy_version_is_reviewed_approved_by_another_admin_and_published_keeping_history(): void
    {
        $author = $this->actingAsCore('core_admin');
        $before = HierarchyVersion::current();
        $id = $this->postJson('/api/v1/hierarchy-versions', ['version_name' => '2026 restructuring', 'effective_from' => today()->toDateString(), 'legal_basis' => 'Proclamation 1/2026'])
            ->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');
        // Bureau B moves under bureau A in the draft only.
        $this->putJson("/api/v1/hierarchy-versions/{$id}/parent", ['child_organization_id' => $this->bureauB->id, 'parent_organization_id' => $this->bureauA->id])->assertOk();
        $this->putJson("/api/v1/hierarchy-versions/{$id}/parent", ['child_organization_id' => $this->city->id, 'parent_organization_id' => $this->bureauB->id])
            ->assertUnprocessable()->assertJsonValidationErrors('parent_organization_id');
        $this->assertSame($before->id, HierarchyVersion::current()->id);

        $this->getJson("/api/v1/hierarchy-versions/{$id}")->assertOk()->assertJsonPath('impact.organizations', 1)->assertJsonPath('impact.positions', 1);
        $this->postJson("/api/v1/hierarchy-versions/{$id}/publish")->assertUnprocessable();
        $this->postJson("/api/v1/hierarchy-versions/{$id}/review")->assertOk()->assertJsonPath('data.status', 'review');
        $this->postJson("/api/v1/hierarchy-versions/{$id}/approve")->assertUnprocessable();

        $this->actingAsCore('core_admin');
        $this->postJson("/api/v1/hierarchy-versions/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
        $this->postJson("/api/v1/hierarchy-versions/{$id}/publish")->assertOk()->assertJsonPath('data.status', 'published');

        $this->assertSame($id, HierarchyVersion::current()->id);
        $this->assertSame(HierarchyVersion::ARCHIVED, $before->fresh()->status);
        $this->assertTrue(OrganizationEdge::query()->where('hierarchy_version_id', $id)->where('child_organization_id', $this->bureauB->id)->where('parent_organization_id', $this->bureauA->id)->exists());
        $this->assertTrue(OrganizationEdge::query()->where('hierarchy_version_id', $before->id)->where('child_organization_id', $this->bureauB->id)->where('parent_organization_id', $this->city->id)->exists());
        $this->assertDatabaseHas('core_audit_logs', ['action' => 'hierarchy.publish', 'subject_id' => $id]);
        $this->assertNotSame($author->id, HierarchyVersion::query()->findOrFail($id)->approved_by);

        // Today's tree has the move; the scope of bureau A now includes bureau B.
        $nodes = collect($this->getJson('/api/v1/hierarchy')->assertOk()->json('nodes'))->keyBy('id');
        $this->assertSame($this->bureauA->id, $nodes[$this->bureauB->id]['parent_id']);
    }

    public function test_the_hierarchy_in_force_on_an_earlier_day_stays_queryable(): void
    {
        $this->actingAsCore('core_admin');
        HierarchyVersion::query()->update(['effective_from' => today()->subMonth()]);
        $this->putJson('/api/v1/organizations/'.$this->bureauB->id, ['parent_organization_id' => $this->bureauA->id])->assertOk();

        $today = collect($this->getJson('/api/v1/hierarchy')->json('nodes'))->keyBy('id');
        $earlier = collect($this->getJson('/api/v1/hierarchy?on='.today()->subWeek()->toDateString())->json('nodes'))->keyBy('id');

        $this->assertSame($this->bureauA->id, $today[$this->bureauB->id]['parent_id']);
        $this->assertSame($this->city->id, $earlier[$this->bureauB->id]['parent_id']);
    }

    public function test_immediate_parent_changes_publish_one_snapshot_per_day(): void
    {
        $this->actingAsCore('core_admin');

        $this->putJson('/api/v1/organizations/'.$this->bureauB->id, ['parent_organization_id' => $this->bureauA->id])->assertOk();
        $this->putJson('/api/v1/organizations/'.$this->bureauB->id, ['parent_organization_id' => $this->city->id])->assertOk();

        $this->assertSame(2, HierarchyVersion::query()->count());
        $this->assertSame(1, HierarchyVersion::query()->where('status', HierarchyVersion::PUBLISHED)->count());
    }

    public function test_hierarchy_versions_are_for_city_wide_administrators_only(): void
    {
        $this->actingAsCore('core_admin', $this->bureauA);
        $this->getJson('/api/v1/hierarchy-versions')->assertForbidden();

        $this->actingAsCore('core_officer');
        $this->postJson('/api/v1/hierarchy-versions', ['version_name' => 'x', 'effective_from' => today()->toDateString()])->assertForbidden();
    }
}

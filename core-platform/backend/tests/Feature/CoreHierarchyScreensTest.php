<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\HierarchyVersion;
use App\Models\Organization;
use App\Models\OrganizationEdge;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsGoldenPath;
use Tests\TestCase;

/** P1 (ADR 0008): the governed hierarchy on the EUISIS screens, over ManageHierarchyVersion. */
class CoreHierarchyScreensTest extends TestCase
{
    use BuildsGoldenPath, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildGoldenPath();
    }

    private function signIn(string $role = 'core_admin', ?Organization $scope = null): User
    {
        $user = User::query()->create([
            'name' => $role.' '.Str::random(4), 'email' => Str::random(8).'@core.test', 'password' => 'secret-password',
            'role' => $role, 'scope_organization_id' => $scope?->id,
            'scope_type' => $scope === null ? User::SCOPE_CITYWIDE : User::SCOPE_SUBTREE, 'is_active' => true,
        ]);
        Auth::forgetGuards();
        $this->actingAs($user, 'web');

        return $user;
    }

    private function draft(string $name = '2026 restructuring'): HierarchyVersion
    {
        $this->get('/hierarchy-versions/create')->assertOk()->assertInertia(fn ($page) => $page->component('HierarchyVersions/Create'));
        $this->post('/hierarchy-versions', ['version_name' => $name, 'effective_from' => today()->toDateString(), 'effective_to' => '', 'source_document' => 'Proclamation 1/2026', 'notes' => ''])
            ->assertSessionHasNoErrors()->assertRedirect();

        return HierarchyVersion::query()->where('version_name', $name)->firstOrFail();
    }

    private function edge(HierarchyVersion $version, Organization $child): OrganizationEdge
    {
        return OrganizationEdge::query()->where('hierarchy_version_id', $version->id)->where('child_organization_id', $child->id)->firstOrFail();
    }

    public function test_a_draft_is_prepared_reviewed_approved_by_another_administrator_and_published(): void
    {
        $author = $this->signIn();
        $before = HierarchyVersion::current();
        $this->get('/hierarchy-versions')->assertOk()->assertInertia(fn ($page) => $page->where('can.create', true));
        $draft = $this->draft();
        $this->assertSame('draft', $draft->status);
        $this->assertSame(2, OrganizationEdge::query()->where('hierarchy_version_id', $draft->id)->count());

        $this->patch("/hierarchy-versions/{$draft->id}", ['version_name' => '2026 restructuring (final)', 'effective_from' => today()->toDateString(), 'effective_to' => '', 'source_document' => '', 'notes' => 'Council decision'])
            ->assertSessionHasNoErrors()->assertRedirect("/hierarchy-versions/{$draft->id}");
        $this->assertSame('Council decision', $draft->fresh()->notes);

        $this->get("/hierarchy-versions/{$draft->id}/tree/edit")->assertOk()->assertInertia(fn ($page) => $page->component('HierarchyVersions/EditTree')
            ->where('can.manageTree', true)->where('relationshipTypes', [OrganizationEdge::REPORTS_TO])->where('edges.0.can.update', true));
        $this->getJson("/hierarchy-versions/{$draft->id}/organization-options?q=BUR-A")->assertOk()->assertJsonPath('options.0.id', $this->bureauA->id);

        // Bureau B already reports to the city: adding a second parent is refused; editing its relation moves it.
        $relation = ['parent_organization_id' => $this->bureauA->id, 'child_organization_id' => $this->bureauB->id, 'relationship_type' => OrganizationEdge::REPORTS_TO, 'effective_from' => '', 'effective_to' => ''];
        $this->post("/hierarchy-versions/{$draft->id}/edges", $relation)->assertSessionHasErrors('child_organization_id');
        $this->patch("/hierarchy-versions/{$draft->id}/edges/".$this->edge($draft, $this->bureauB)->id, $relation)->assertSessionHasNoErrors();
        $this->patch("/hierarchy-versions/{$draft->id}/edges/".$this->edge($draft, $this->bureauB)->id, ['child_organization_id' => $this->bureauA->id] + $relation)
            ->assertSessionHasErrors('child_organization_id');
        $this->patch("/hierarchy-versions/{$draft->id}/edges/".$this->edge($draft, $this->bureauA)->id, ['parent_organization_id' => $this->bureauB->id, 'child_organization_id' => $this->bureauA->id] + $relation)
            ->assertSessionHasErrors('parent_organization_id');
        $this->assertSame($this->bureauA->id, $this->edge($draft, $this->bureauB)->parent_organization_id);
        $this->assertSame($before->id, HierarchyVersion::current()->id);

        $this->get("/hierarchy-versions/{$draft->id}")->assertOk()->assertInertia(fn ($page) => $page->where('governance.status', 'draft')
            ->where('governance.impact.organizations', 1)->where('governance.impact.positions', 1)->where('governance.can.review', true)->where('governance.can.publish', false));
        $this->post("/hierarchy-versions/{$draft->id}/review")->assertSessionHasNoErrors();
        $this->post("/hierarchy-versions/{$draft->id}/approve")->assertSessionHasErrors('status');
        $this->get("/hierarchy-versions/{$draft->id}")->assertInertia(fn ($page) => $page->where('governance.approval_blocked', true)->where('governance.can.approve', false));

        $approver = $this->signIn();
        $this->get("/hierarchy-versions/{$draft->id}")->assertInertia(fn ($page) => $page->where('governance.can.approve', true)->where('governance.steps.1.by', $author->name));
        $this->post("/hierarchy-versions/{$draft->id}/approve")->assertSessionHasNoErrors();
        $this->post("/hierarchy-versions/{$draft->id}/publish")->assertSessionHasNoErrors();

        $this->assertSame($draft->id, HierarchyVersion::current()->id);
        $this->assertSame($approver->id, (int) $draft->fresh()->approved_by);
        $this->assertSame(HierarchyVersion::ARCHIVED, $before->fresh()->status);
        foreach (['hierarchy.created', 'hierarchy.updated', 'hierarchy.parent_changed', 'hierarchy.review', 'hierarchy.approve', 'hierarchy.publish'] as $action) {
            $this->assertDatabaseHas('core_audit_logs', ['action' => $action, 'subject_id' => $draft->id]);
        }
    }

    public function test_a_published_or_submitted_version_is_not_edited(): void
    {
        $this->signIn();
        $current = HierarchyVersion::current();
        $edge = $this->edge($current, $this->bureauB);
        $relation = ['parent_organization_id' => $this->bureauA->id, 'child_organization_id' => $this->bureauB->id, 'relationship_type' => OrganizationEdge::REPORTS_TO];

        $this->get("/hierarchy-versions/{$current->id}/edit")->assertRedirect("/hierarchy-versions/{$current->id}");
        $this->get("/hierarchy-versions/{$current->id}/tree/edit")->assertRedirect("/hierarchy-versions/{$current->id}");
        $this->patch("/hierarchy-versions/{$current->id}", ['version_name' => 'Rewritten', 'effective_from' => today()->toDateString()])->assertSessionHasErrors('status');
        $this->patch("/hierarchy-versions/{$current->id}/edges/{$edge->id}", $relation)->assertSessionHasErrors('status');
        $this->delete("/hierarchy-versions/{$current->id}/edges/{$edge->id}")->assertSessionHasErrors('status');
        $this->get("/hierarchy-versions/{$current->id}")->assertInertia(fn ($page) => $page->where('can.edit', false)->where('can.manageTree', false)
            ->where('governance.can.review', false)->where('governance.can.reject', false));
        $this->assertSame($this->city->id, $edge->fresh()->parent_organization_id);

        $draft = $this->draft();
        $this->post("/hierarchy-versions/{$draft->id}/review")->assertSessionHasNoErrors();
        $this->post("/hierarchy-versions/{$draft->id}/edges/", ['parent_organization_id' => $this->bureauA->id, 'child_organization_id' => $this->city->id, 'relationship_type' => OrganizationEdge::REPORTS_TO])
            ->assertSessionHasErrors('status');
        // A relation of another version is not this version's to change.
        $this->patch("/hierarchy-versions/{$draft->id}/edges/{$edge->id}", $relation)->assertNotFound();
        $this->assertDatabaseMissing('core_audit_logs', ['action' => 'hierarchy.parent_changed']);
    }

    public function test_an_administrator_who_changed_the_draft_does_not_approve_it_and_a_rejection_clears_the_review(): void
    {
        $this->signIn();
        $draft = $this->draft();
        $editor = $this->signIn();
        $this->delete("/hierarchy-versions/{$draft->id}/edges/".$this->edge($draft, $this->bureauB)->id)->assertSessionHasNoErrors();
        $this->assertFalse(OrganizationEdge::query()->where('hierarchy_version_id', $draft->id)->where('child_organization_id', $this->bureauB->id)->exists());

        $this->signIn();
        $this->post("/hierarchy-versions/{$draft->id}/review")->assertSessionHasNoErrors();
        Auth::forgetGuards();
        $this->actingAs($editor, 'web');
        $this->post("/hierarchy-versions/{$draft->id}/approve")->assertSessionHasErrors('status');

        $this->signIn();
        $this->post("/hierarchy-versions/{$draft->id}/reject")->assertSessionHasNoErrors();
        $draft->refresh();
        $this->assertSame('draft', $draft->status);
        $this->assertNull($draft->reviewed_by);
        $this->assertDatabaseHas('core_audit_logs', ['action' => 'hierarchy.reject', 'subject_id' => $draft->id]);
    }

    public function test_only_a_city_wide_administrator_governs_the_hierarchy(): void
    {
        $current = HierarchyVersion::current();
        $edge = $this->edge($current, $this->bureauB);
        foreach ([['core_admin', $this->bureauA], ['core_officer', null], ['core_viewer', null]] as [$role, $scope]) {
            $this->signIn($role, $scope);
            $this->get('/hierarchy-versions')->assertOk()->assertInertia(fn ($page) => $page->where('can.create', false));
            $this->get("/hierarchy-versions/{$current->id}")->assertOk()->assertInertia(fn ($page) => $page->where('governance', null)->where('can.edit', false));
            $this->get('/hierarchy-versions/create')->assertForbidden();
            $this->post('/hierarchy-versions', ['version_name' => 'Mine', 'effective_from' => today()->toDateString()])->assertForbidden();
            $this->get("/hierarchy-versions/{$current->id}/organization-options")->assertForbidden();
            $this->delete("/hierarchy-versions/{$current->id}/edges/{$edge->id}")->assertForbidden();
            $this->post("/hierarchy-versions/{$current->id}/reject")->assertForbidden();
        }
        $this->assertSame(1, HierarchyVersion::query()->count());
    }
}

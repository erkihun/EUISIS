<?php

declare(strict_types=1);

use App\Actions\Organizations\PublishHierarchyVersionAction;
use App\Enums\HierarchyVersionStatus;
use App\Enums\OrganizationDesignStatus;
use App\Enums\OrganizationRelationshipType;
use App\Enums\OrganizationScopeType;
use App\Enums\OrganizationStatus;
use App\Models\HierarchyVersion;
use App\Models\Organization;
use App\Models\OrganizationDesignStudy;
use App\Models\OrganizationEdge;
use App\Models\OrganizationType;
use App\Models\User;
use App\Models\UserOrganizationScope;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/*
 * Organization scope and segregation of duties (brief §37–38, §53):
 * direct, subtree and city scopes on design studies, and the rule that
 * one person never both prepares and approves. Tests 93–94.
 */

beforeEach(function (): void {
    $names = array_column(require base_path('database/seeders/data/organization-design-permissions.php'), 'name');
    foreach ($names as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    Role::findOrCreate('Super Admin', 'web');
    Role::findOrCreate('Design All', 'web')->syncPermissions($names);
});

function sdOrganization(string $suffix): Organization
{
    $type = OrganizationType::query()->firstOrCreate(['code' => 'SD-BUREAU'], ['name_en' => 'SD Bureau', 'is_demo' => false]);

    return Organization::query()->create(['organization_type_id' => $type->id, 'code' => 'SD-'.$suffix.'-'.uniqid(), 'name_en' => 'SD Org '.$suffix, 'status' => OrganizationStatus::Active, 'effective_from' => now()->subYear()->toDateString(), 'is_demo' => false]);
}

function sdUser(Organization $organization, OrganizationScopeType $scope): User
{
    $user = User::factory()->create();
    $user->assignRole('Design All');
    UserOrganizationScope::query()->create(['user_id' => $user->id, 'organization_id' => $organization->id, 'scope_type' => $scope, 'is_active' => true, 'effective_from' => now()->subDay()->toDateString()]);

    return $user->fresh();
}

function sdStudy(Organization $organization): OrganizationDesignStudy
{
    return OrganizationDesignStudy::query()->create(['organization_id' => $organization->id, 'reference_number' => 'ODS-'.substr(uniqid(), -6), 'title_en' => 'Study', 'study_type' => 'review', 'status' => OrganizationDesignStatus::Draft]);
}

// 93 ─────────────────────────────────────────────────────────────────────────
test('a direct scope opens only its own organization, a subtree scope follows the published hierarchy, a city role sees everything', function (): void {
    $parent = sdOrganization('P');
    $child = sdOrganization('C');
    $foreign = sdOrganization('X');
    $version = HierarchyVersion::query()->create(['version_name' => 'sd-v-'.uniqid(), 'status' => HierarchyVersionStatus::Draft, 'effective_from' => now()->subDay()->toDateString(), 'is_demo' => false]);
    OrganizationEdge::query()->create(['hierarchy_version_id' => $version->id, 'parent_organization_id' => $parent->id, 'child_organization_id' => $child->id, 'relationship_type' => OrganizationRelationshipType::ReportsTo, 'effective_from' => now()->toDateString()]);
    $publisher = User::factory()->create();
    $publisher->assignRole('Super Admin');
    app(PublishHierarchyVersionAction::class)->execute($version, $publisher);

    $parentStudy = sdStudy($parent);
    $childStudy = sdStudy($child);
    $foreignStudy = sdStudy($foreign);
    $direct = sdUser($parent, OrganizationScopeType::Self);
    $subtree = sdUser($parent, OrganizationScopeType::Subtree);
    $city = User::factory()->create();
    $city->assignRole('Super Admin');

    $this->actingAs($direct)->get(route('organization-design.studies.show', $parentStudy))->assertOk();
    $this->actingAs($direct)->get(route('organization-design.studies.show', $childStudy))->assertForbidden();
    $this->actingAs($direct)->get(route('organization-design.studies.show', $foreignStudy))->assertForbidden();
    $this->actingAs($direct)->from('/')->put(route('organization-design.studies.update', $foreignStudy), ['title_en' => 'Hijacked'])->assertForbidden();

    $this->actingAs($subtree)->get(route('organization-design.studies.show', $parentStudy))->assertOk();
    $this->actingAs($subtree)->get(route('organization-design.studies.show', $childStudy))->assertOk();
    $this->actingAs($subtree)->get(route('organization-design.studies.show', $foreignStudy))->assertForbidden();

    $this->actingAs($city)->get(route('organization-design.studies.show', $foreignStudy))->assertOk();
    $this->actingAs($city)->get(route('organization-design.studies.index'))->assertInertia(fn ($page) => $page->where('studies.total', 3));
    $this->actingAs($direct)->get(route('organization-design.studies.index'))->assertInertia(fn ($page) => $page->where('studies.total', 1));
    expect($foreignStudy->fresh()->title_en)->toBe('Study');
});

// 94 ─────────────────────────────────────────────────────────────────────────
test('segregation of duties: holding every permission does not let the same person prepare and approve', function (): void {
    $organization = sdOrganization('S');
    $almighty = sdUser($organization, OrganizationScopeType::Self);
    $this->actingAs($almighty)->post(route('organization-design.studies.store'), ['organization_id' => $organization->id, 'title_en' => 'Own study', 'study_type' => 'review', 'reason' => 'Review'])->assertRedirect();
    $study = OrganizationDesignStudy::query()->where('organization_id', $organization->id)->firstOrFail();
    $unitType = \App\Models\OrganizationUnitType::query()->firstOrCreate(['code' => 'SD-DIR'], ['name_en' => 'Directorate', 'is_active' => true]);
    $this->actingAs($almighty)->post(route('organization-design.studies.proposals.store', $study), ['proposal_type' => 'create', 'name_en' => 'New unit', 'proposed_unit_type_id' => $unitType->id])->assertRedirect();
    $this->actingAs($almighty)->post(route('organization-design.studies.submit', $study))->assertRedirect();
    expect($study->fresh()->status)->toBe(OrganizationDesignStatus::UnderReview);
    $this->actingAs($almighty)->from('/')->post(route('organization-design.studies.approve', $study))->assertSessionHasErrors('status');
    expect($study->fresh()->status)->toBe(OrganizationDesignStatus::UnderReview);
});

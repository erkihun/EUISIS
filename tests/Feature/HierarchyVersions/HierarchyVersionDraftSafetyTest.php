<?php

declare(strict_types=1);

use App\Actions\Organizations\CreateHierarchyVersionAction;
use App\Actions\Organizations\PublishHierarchyVersionAction;
use App\Enums\HierarchyVersionStatus;
use App\Models\HierarchyVersion;
use App\Models\Organization;
use App\Models\OrganizationEdge;
use App\Models\OrganizationType;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/*
 * A new draft starts as a copy of the published structure, and publishing a
 * draft that leaves out an active organization of the live tree is refused:
 * either would otherwise drop organizations out of the tree and out of every
 * administrator's scope.
 */

beforeEach(function (): void {
    $type = OrganizationType::query()->create(['code' => 'HVS-T', 'name_en' => 'Type']);
    $make = fn (string $code, string $status = 'active') => Organization::query()->create([
        'organization_type_id' => $type->id, 'code' => $code, 'name_en' => 'Org '.$code, 'name_am' => 'ተቋም', 'status' => $status,
    ]);
    $this->root = $make('HVS-ROOT');
    $this->a = $make('HVS-A');
    $this->b = $make('HVS-B');
    $this->published = HierarchyVersion::query()->create(['version_name' => 'Live', 'status' => HierarchyVersionStatus::Published->value, 'effective_from' => now()->subYear()->toDateString()]);
    foreach ([$this->a, $this->b] as $child) {
        OrganizationEdge::query()->create(['hierarchy_version_id' => $this->published->id, 'parent_organization_id' => $this->root->id, 'child_organization_id' => $child->id, 'relationship_type' => 'reports_to']);
    }
    $this->actor = User::factory()->create();
});

it('starts a new draft as a copy of the published structure', function (): void {
    $draft = app(CreateHierarchyVersionAction::class)->execute(['version_name' => 'Next', 'effective_from' => now()->toDateString()], $this->actor);

    expect($draft->status)->toBe(HierarchyVersionStatus::Draft)
        ->and(OrganizationEdge::query()->where('hierarchy_version_id', $draft->id)->pluck('child_organization_id')->sort()->values()->all())
        ->toBe(collect([$this->a->id, $this->b->id])->sort()->values()->all())
        // The published version is untouched.
        ->and(OrganizationEdge::query()->where('hierarchy_version_id', $this->published->id)->count())->toBe(2);
});

it('publishes a copied draft with a new organization added', function (): void {
    $draft = app(CreateHierarchyVersionAction::class)->execute(['version_name' => 'Next', 'effective_from' => now()->toDateString()], $this->actor);
    $new = Organization::query()->create(['organization_type_id' => $this->root->organization_type_id, 'code' => 'HVS-NEW', 'name_en' => 'New', 'name_am' => 'አዲስ', 'status' => 'active']);
    OrganizationEdge::query()->create(['hierarchy_version_id' => $draft->id, 'parent_organization_id' => $this->root->id, 'child_organization_id' => $new->id, 'relationship_type' => 'reports_to']);

    app(PublishHierarchyVersionAction::class)->execute($draft, $this->actor);

    expect($draft->fresh()->status)->toBe(HierarchyVersionStatus::Published)
        ->and($this->published->fresh()->status)->toBe(HierarchyVersionStatus::Archived);
});

it('refuses to publish a draft that drops an active organization of the live tree', function (): void {
    $draft = HierarchyVersion::query()->create(['version_name' => 'Partial', 'status' => HierarchyVersionStatus::Draft->value, 'effective_from' => now()->toDateString()]);
    OrganizationEdge::query()->create(['hierarchy_version_id' => $draft->id, 'parent_organization_id' => $this->root->id, 'child_organization_id' => $this->a->id, 'relationship_type' => 'reports_to']);

    expect(fn () => app(PublishHierarchyVersionAction::class)->execute($draft, $this->actor))
        ->toThrow(ValidationException::class, 'HVS-B');

    expect($this->published->fresh()->status)->toBe(HierarchyVersionStatus::Published);
});

it('lets a draft leave out an organization that is no longer active', function (): void {
    $this->b->update(['status' => 'dissolved']);
    $draft = HierarchyVersion::query()->create(['version_name' => 'Without B', 'status' => HierarchyVersionStatus::Draft->value, 'effective_from' => now()->toDateString()]);
    OrganizationEdge::query()->create(['hierarchy_version_id' => $draft->id, 'parent_organization_id' => $this->root->id, 'child_organization_id' => $this->a->id, 'relationship_type' => 'reports_to']);

    app(PublishHierarchyVersionAction::class)->execute($draft, $this->actor);

    expect($draft->fresh()->status)->toBe(HierarchyVersionStatus::Published);
});

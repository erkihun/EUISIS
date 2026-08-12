<?php

declare(strict_types=1);

use App\Actions\CodeRules\GenerateCodeAction;
use App\Enums\AuditEventType;
use App\Enums\CodeRuleEntityType;
use App\Enums\OrganizationStatus;
use App\Enums\OrganizationUnitStatus;
use App\Models\AuditLog;
use App\Models\CodeRule;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Create an OrganizationType for tests (always fresh, unique code).
 */
function copyTestOrgType(): OrganizationType
{
    return OrganizationType::query()->create([
        'code' => 'copy-type-'.uniqid(),
        'name_en' => 'Copy Test Type',
    ]);
}

/**
 * Create an Organization for tests.
 */
function copyTestOrg(string $suffix = ''): Organization
{
    return Organization::query()->create([
        'organization_type_id' => copyTestOrgType()->id,
        'code' => 'ORG-COPY-'.uniqid($suffix),
        'name_en' => 'Copy Test Org '.$suffix,
        'status' => OrganizationStatus::Active,
    ]);
}

/**
 * Create an OrganizationUnit (no code rule needed — code is supplied explicitly).
 */
function copyTestUnit(
    Organization $org,
    string $nameEn,
    ?OrganizationUnit $parent = null,
): OrganizationUnit {
    return OrganizationUnit::query()->create([
        'organization_id' => $org->id,
        'parent_unit_id' => $parent?->id,
        'code' => 'UNIT-'.uniqid(),
        'name_en' => $nameEn,
        'unit_type' => 'unit',
        'status' => OrganizationUnitStatus::Active,
    ]);
}

/**
 * Create a user with all organization-unit permissions.
 */
function copyTestAdminUser(): User
{
    $perms = [
        'organization-units.viewAny',
        'organization-units.view',
        'organization-units.create',
        'organization-units.update',
        'organization-units.delete',
        'organization-units.restore',
    ];

    foreach ($perms as $perm) {
        Permission::findOrCreate($perm, 'web');
    }

    $role = Role::findOrCreate('CopyTestAdmin', 'web');
    $role->syncPermissions($perms);

    $user = User::factory()->create();
    $user->assignRole('CopyTestAdmin');

    return $user;
}

/**
 * Bind a counter-based fake GenerateCodeAction so no real CodeRule is needed.
 */
function bindFakeCodeGenerator(): void
{
    $counter = 0;

    app()->bind(GenerateCodeAction::class, function () use (&$counter) {
        return new class($counter) extends GenerateCodeAction
        {
            public function __construct(private int &$count) {}

            public function execute(
                CodeRuleEntityType|string $entityType,
                array $context = [],
                ?User $actor = null,
                ?string $manualCode = null,
                string $field = 'code',
                ?string $entityId = null,
                ?CodeRule $resolvedRule = null,
            ): string {
                $this->count++;

                return 'GEN-'.strtoupper(is_string($entityType) ? $entityType : $entityType->value).'-'.$this->count;
            }
        };
    });
}

// ── Tests ─────────────────────────────────────────────────────────────────────

test('copies full organization unit tree', function (): void {
    bindFakeCodeGenerator();

    $actor = copyTestAdminUser();
    $sourceOrg = copyTestOrg('src');
    $targetOrg = copyTestOrg('tgt');

    $root = copyTestUnit($sourceOrg, 'Root Unit');
    $child = copyTestUnit($sourceOrg, 'Child Unit', $root);
    copyTestUnit($sourceOrg, 'Grandchild Unit', $child);

    $this->actingAs($actor)
        ->post(route('organization-units.copy-structure'), [
            'source_organization_id' => $sourceOrg->id,
            'source_unit_id' => null,
            'target_organization_id' => $targetOrg->id,
            'target_parent_unit_id' => null,
            'copy_positions' => false,
            'copy_functional_relationships' => false,
            'status' => 'active',
        ])
        ->assertRedirect(route('organization-units.index'));

    $copiedUnits = OrganizationUnit::query()
        ->where('organization_id', $targetOrg->id)
        ->get();

    expect($copiedUnits)->toHaveCount(3);
});

test('copies partial tree from source unit', function (): void {
    bindFakeCodeGenerator();

    $actor = copyTestAdminUser();
    $sourceOrg = copyTestOrg('src-partial');
    $targetOrg = copyTestOrg('tgt-partial');

    $root = copyTestUnit($sourceOrg, 'Root Unit');
    $child = copyTestUnit($sourceOrg, 'Child Unit', $root);
    copyTestUnit($sourceOrg, 'Grandchild Unit', $child);

    // Only copy from child (child + grandchild = 2 units)
    $this->actingAs($actor)
        ->post(route('organization-units.copy-structure'), [
            'source_organization_id' => $sourceOrg->id,
            'source_unit_id' => $child->id,
            'target_organization_id' => $targetOrg->id,
            'target_parent_unit_id' => null,
            'copy_positions' => false,
            'copy_functional_relationships' => false,
            'status' => 'active',
        ])
        ->assertRedirect(route('organization-units.index'));

    $copiedUnits = OrganizationUnit::query()
        ->where('organization_id', $targetOrg->id)
        ->get();

    expect($copiedUnits)->toHaveCount(2);
});

test('generates new codes (does not reuse source codes)', function (): void {
    bindFakeCodeGenerator();

    $actor = copyTestAdminUser();
    $sourceOrg = copyTestOrg('src-code');
    $targetOrg = copyTestOrg('tgt-code');

    $unit = copyTestUnit($sourceOrg, 'Unit To Copy');

    $this->actingAs($actor)
        ->post(route('organization-units.copy-structure'), [
            'source_organization_id' => $sourceOrg->id,
            'source_unit_id' => null,
            'target_organization_id' => $targetOrg->id,
            'target_parent_unit_id' => null,
            'copy_positions' => false,
            'copy_functional_relationships' => false,
            'status' => 'active',
        ])
        ->assertRedirect(route('organization-units.index'));

    $copiedUnit = OrganizationUnit::query()
        ->where('organization_id', $targetOrg->id)
        ->where('name_en', 'Unit To Copy')
        ->firstOrFail();

    // The copied unit's code must differ from the source code
    expect($copiedUnit->code)->not->toBe($unit->code);
    // Generated code follows our fake pattern
    expect($copiedUnit->code)->toStartWith('GEN-');
});

test('does not copy employees', function (): void {
    bindFakeCodeGenerator();

    $actor = copyTestAdminUser();
    $sourceOrg = copyTestOrg('src-emp');
    $targetOrg = copyTestOrg('tgt-emp');

    $unit = copyTestUnit($sourceOrg, 'Unit With Employees');

    // Verify no employee assignments are created for target org
    $this->actingAs($actor)
        ->post(route('organization-units.copy-structure'), [
            'source_organization_id' => $sourceOrg->id,
            'source_unit_id' => null,
            'target_organization_id' => $targetOrg->id,
            'target_parent_unit_id' => null,
            'copy_positions' => false,
            'copy_functional_relationships' => false,
            'status' => 'active',
        ])
        ->assertRedirect(route('organization-units.index'));

    // No employee assignments should exist for the target org
    $copiedUnit = OrganizationUnit::query()
        ->where('organization_id', $targetOrg->id)
        ->firstOrFail();

    expect($copiedUnit->assignments()->count())->toBe(0);
});

test('copies positions when copy_positions is true', function (): void {
    bindFakeCodeGenerator();

    $actor = copyTestAdminUser();
    $sourceOrg = copyTestOrg('src-pos');
    $targetOrg = copyTestOrg('tgt-pos');

    $unit = copyTestUnit($sourceOrg, 'Unit With Positions');

    // Create positions for the source unit
    Position::query()->create([
        'organization_id' => $sourceOrg->id,
        'organization_unit_id' => $unit->id,
        'job_position_code' => 'JPC-'.uniqid(),
        'code' => 'POS-001',
        'title_en' => 'Manager',
        'is_active' => true,
    ]);
    Position::query()->create([
        'organization_id' => $sourceOrg->id,
        'organization_unit_id' => $unit->id,
        'job_position_code' => 'JPC-'.uniqid(),
        'code' => 'POS-002',
        'title_en' => 'Analyst',
        'is_active' => true,
    ]);

    $this->actingAs($actor)
        ->post(route('organization-units.copy-structure'), [
            'source_organization_id' => $sourceOrg->id,
            'source_unit_id' => null,
            'target_organization_id' => $targetOrg->id,
            'target_parent_unit_id' => null,
            'copy_positions' => true,
            'copy_functional_relationships' => false,
            'status' => 'active',
        ])
        ->assertRedirect(route('organization-units.index'));

    $copiedUnit = OrganizationUnit::query()
        ->where('organization_id', $targetOrg->id)
        ->firstOrFail();

    $positionCount = Position::query()
        ->where('organization_unit_id', $copiedUnit->id)
        ->count();

    expect($positionCount)->toBe(2);
});

test('does not copy positions when copy_positions is false', function (): void {
    bindFakeCodeGenerator();

    $actor = copyTestAdminUser();
    $sourceOrg = copyTestOrg('src-nopos');
    $targetOrg = copyTestOrg('tgt-nopos');

    $unit = copyTestUnit($sourceOrg, 'Unit With Positions');

    Position::query()->create([
        'organization_id' => $sourceOrg->id,
        'organization_unit_id' => $unit->id,
        'job_position_code' => 'JPC-'.uniqid(),
        'code' => 'POS-003',
        'title_en' => 'Director',
        'is_active' => true,
    ]);

    $this->actingAs($actor)
        ->post(route('organization-units.copy-structure'), [
            'source_organization_id' => $sourceOrg->id,
            'source_unit_id' => null,
            'target_organization_id' => $targetOrg->id,
            'target_parent_unit_id' => null,
            'copy_positions' => false,
            'copy_functional_relationships' => false,
            'status' => 'active',
        ])
        ->assertRedirect(route('organization-units.index'));

    $copiedUnit = OrganizationUnit::query()
        ->where('organization_id', $targetOrg->id)
        ->firstOrFail();

    $positionCount = Position::query()
        ->where('organization_unit_id', $copiedUnit->id)
        ->count();

    expect($positionCount)->toBe(0);
});

test('rejects unauthorized user (cannot manage target org)', function (): void {
    $unauthorizedUser = User::factory()->create();
    // Give no permissions at all

    $sourceOrg = copyTestOrg('src-unauth');
    $targetOrg = copyTestOrg('tgt-unauth');

    copyTestUnit($sourceOrg, 'Unit');

    $this->actingAs($unauthorizedUser)
        ->post(route('organization-units.copy-structure'), [
            'source_organization_id' => $sourceOrg->id,
            'source_unit_id' => null,
            'target_organization_id' => $targetOrg->id,
            'target_parent_unit_id' => null,
            'copy_positions' => false,
            'copy_functional_relationships' => false,
            'status' => 'active',
        ])
        ->assertForbidden();
});

test('audit log is created with correct counts', function (): void {
    bindFakeCodeGenerator();

    $actor = copyTestAdminUser();
    $sourceOrg = copyTestOrg('src-audit');
    $targetOrg = copyTestOrg('tgt-audit');

    $root = copyTestUnit($sourceOrg, 'Root');
    copyTestUnit($sourceOrg, 'Child', $root);

    $this->actingAs($actor)
        ->post(route('organization-units.copy-structure'), [
            'source_organization_id' => $sourceOrg->id,
            'source_unit_id' => null,
            'target_organization_id' => $targetOrg->id,
            'target_parent_unit_id' => null,
            'copy_positions' => false,
            'copy_functional_relationships' => false,
            'status' => 'active',
        ])
        ->assertRedirect(route('organization-units.index'));

    $log = AuditLog::query()
        ->where('event_type', AuditEventType::OrganizationUnitStructureCopied->value)
        ->latest()
        ->first();

    expect($log)->not->toBeNull();
    // Verify the audit log was created for the correct actor and target org
    expect($log->actor_user_id)->toBe($actor->getKey());
    expect($log->organization_id)->toBe($targetOrg->id);
    // new_values is stored as a JSON array; confirm it has values (counts, ids)
    expect($log->new_values)->not->toBeNull();
    expect($log->new_values)->toBeArray();
    // The values array contains: source_org_id, source_unit_id, target_org_id, target_parent_unit_id,
    // units_copied (2), positions_copied (0), copy_positions (false), status ('active')
    // Since WriteAuditLogAction::redact re-indexes array keys, we check by position or presence
    $values = array_values($log->new_values);
    expect(in_array(2, $values, true))->toBeTrue(); // units_copied = 2
    expect(in_array(0, $values, true))->toBeTrue(); // positions_copied = 0
});

test('source_unit_id must belong to source org', function (): void {
    $actor = copyTestAdminUser();
    $sourceOrg = copyTestOrg('src-validation');
    $targetOrg = copyTestOrg('tgt-validation');
    $otherOrg = copyTestOrg('other-validation');

    $unitFromOtherOrg = copyTestUnit($otherOrg, 'Unit In Other Org');

    $this->actingAs($actor)
        ->post(route('organization-units.copy-structure'), [
            'source_organization_id' => $sourceOrg->id,
            'source_unit_id' => $unitFromOtherOrg->id, // belongs to $otherOrg, not $sourceOrg
            'target_organization_id' => $targetOrg->id,
            'target_parent_unit_id' => null,
            'copy_positions' => false,
            'copy_functional_relationships' => false,
            'status' => 'active',
        ])
        ->assertSessionHasErrors(['source_unit_id']);
});

test('target_parent_unit_id must belong to target org', function (): void {
    $actor = copyTestAdminUser();
    $sourceOrg = copyTestOrg('src-targetvalid');
    $targetOrg = copyTestOrg('tgt-targetvalid');
    $otherOrg = copyTestOrg('other-targetvalid');

    copyTestUnit($sourceOrg, 'Source Unit');
    $unitFromOtherOrg = copyTestUnit($otherOrg, 'Unit In Other Org');

    $this->actingAs($actor)
        ->post(route('organization-units.copy-structure'), [
            'source_organization_id' => $sourceOrg->id,
            'source_unit_id' => null,
            'target_organization_id' => $targetOrg->id,
            'target_parent_unit_id' => $unitFromOtherOrg->id, // belongs to $otherOrg, not $targetOrg
            'copy_positions' => false,
            'copy_functional_relationships' => false,
            'status' => 'active',
        ])
        ->assertSessionHasErrors(['target_parent_unit_id']);
});

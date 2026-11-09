<?php

declare(strict_types=1);

use App\Enums\CodeRuleEntityType;
use App\Enums\CodeRuleResetFrequency;
use App\Models\CodeRule;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    foreach ([
        'code-rules.preview',
        'code-rules.view',
        'organizations.manage',
        'employees.manage',
        'positions.create',
        'service-types.create',
        'organization-types.create',
    ] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    Role::findOrCreate('Super Admin', 'web')->syncPermissions(Permission::all());
});

// Helper: create a user with code-rules.preview only
function previewOnlyUser(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo('code-rules.preview');

    return $user;
}

// Helper: create a user with no permissions at all
function noPermissionUser(): User
{
    return User::factory()->create();
}

// Helper: super admin
function previewSuperAdmin(): User
{
    $user = User::factory()->create();
    $user->assignRole('Super Admin');

    return $user;
}

// Helper: create a global code rule for the given entity type
function makeCodeRule(string $entityType, string $prefix, string $format, int $seqLen = 4): CodeRule
{
    return CodeRule::query()->create([
        'entity_type' => $entityType,
        'scope_type' => null,
        'scope_id' => null,
        'active_scope_key' => CodeRule::buildActiveScopeKey($entityType),
        'name_en' => ucfirst(str_replace('_', ' ', $entityType)).' Code',
        'prefix' => $prefix,
        'format' => $format,
        'separator' => '-',
        'sequence_length' => $seqLen,
        'next_number' => 1,
        'reset_frequency' => CodeRuleResetFrequency::Never,
        'year_format' => 'Y',
        'is_active' => true,
        'allow_manual_override' => false,
        'require_approval_for_override' => true,
    ]);
}

// ── Test 1: preview endpoint requires authentication ──────────────────────────

it('preview-code endpoint requires authentication', function (): void {
    $this->postJson(route('code-rules.preview-code'), [
        'entity_type' => CodeRuleEntityType::Organization->value,
    ])->assertUnauthorized();
});

// ── Test 2: user without any relevant permission is rejected ──────────────────

it('preview-code endpoint rejects users without any relevant permission', function (): void {
    $this->actingAs(noPermissionUser())
        ->postJson(route('code-rules.preview-code'), [
            'entity_type' => CodeRuleEntityType::Organization->value,
        ])->assertForbidden();
});

// ── Test 3: preview returns correct code for organization ────────────────────

it('preview-code returns correct auto-generated preview for organization', function (): void {
    makeCodeRule(CodeRuleEntityType::Organization->value, 'ORG', '{PREFIX}-{YEAR}-{SEQUENCE}', 5);

    $this->actingAs(previewOnlyUser())
        ->postJson(route('code-rules.preview-code'), [
            'entity_type' => CodeRuleEntityType::Organization->value,
        ])
        ->assertOk()
        ->assertJsonPath('code', 'ORG-2026-00001')
        ->assertJsonPath('manual_override_allowed', false)
        ->assertJsonPath('requires_override_permission', true)
        ->assertJsonPath('error', null);
});

// ── Test 4: preview returns correct code for employee ────────────────────────

it('preview-code returns correct auto-generated preview for employee', function (): void {
    makeCodeRule(CodeRuleEntityType::Employee->value, 'EMP', '{PREFIX}-{YEAR}-{SEQUENCE}', 6);

    $this->actingAs(previewOnlyUser())
        ->postJson(route('code-rules.preview-code'), [
            'entity_type' => CodeRuleEntityType::Employee->value,
        ])
        ->assertOk()
        ->assertJsonPath('code', 'EMP-2026-000001');
});

// ── Test 5: preview does NOT expose next_number to non-code-rules.view users ──

it('preview-code does not expose next_number to users without code-rules.view', function (): void {
    makeCodeRule(CodeRuleEntityType::Organization->value, 'ORG', '{PREFIX}-{YEAR}-{SEQUENCE}');

    $user = previewOnlyUser(); // has code-rules.preview but NOT code-rules.view

    $response = $this->actingAs($user)
        ->postJson(route('code-rules.preview-code'), [
            'entity_type' => CodeRuleEntityType::Organization->value,
        ])
        ->assertOk()
        ->json();

    expect(array_key_exists('next_number', $response['rule'] ?? []))->toBeFalse();
});

// ── Test 6: super admin sees next_number ─────────────────────────────────────

it('preview-code exposes next_number to users with code-rules.view', function (): void {
    makeCodeRule(CodeRuleEntityType::Organization->value, 'ORG', '{PREFIX}-{YEAR}-{SEQUENCE}');

    $response = $this->actingAs(previewSuperAdmin())
        ->postJson(route('code-rules.preview-code'), [
            'entity_type' => CodeRuleEntityType::Organization->value,
        ])
        ->assertOk()
        ->json();

    expect(isset($response['rule']['next_number']))->toBeTrue();
});

// ── Test 7: preview returns safe error when no rule found ────────────────────

it('preview-code returns safe error payload when no rule is configured', function (): void {
    // No code rule seeded — should return 200 with error message but no exception

    $this->actingAs(previewOnlyUser())
        ->postJson(route('code-rules.preview-code'), [
            'entity_type' => CodeRuleEntityType::Organization->value,
        ])
        ->assertOk()
        ->assertJsonPath('code', null)
        ->assertJsonPath('rule', null);
});

// ── Test 8: preview does NOT increment next_number ───────────────────────────

it('preview-code does not increment next_number', function (): void {
    $rule = makeCodeRule(CodeRuleEntityType::Organization->value, 'ORG', '{PREFIX}-{YEAR}-{SEQUENCE}');

    expect($rule->next_number)->toBe(1);

    $this->actingAs(previewOnlyUser())
        ->postJson(route('code-rules.preview-code'), [
            'entity_type' => CodeRuleEntityType::Organization->value,
        ])
        ->assertOk();

    $this->actingAs(previewOnlyUser())
        ->postJson(route('code-rules.preview-code'), [
            'entity_type' => CodeRuleEntityType::Organization->value,
        ])
        ->assertOk();

    // Calling preview twice should not change next_number
    expect($rule->fresh()->next_number)->toBe(1);
});

// ── Test 9: preview respects context for scoped rules ────────────────────────

it('preview-code returns rule name and entity type in response', function (): void {
    makeCodeRule(CodeRuleEntityType::Position->value, 'POS', '{PREFIX}-{SEQUENCE}');

    $response = $this->actingAs(previewOnlyUser())
        ->postJson(route('code-rules.preview-code'), [
            'entity_type' => CodeRuleEntityType::Position->value,
        ])
        ->assertOk()
        ->json();

    expect($response['rule']['entity_type'])->toBe(CodeRuleEntityType::Position->value)
        ->and($response['rule']['name'])->toBeString();
});

// ── Test 10: validate entity_type is required ────────────────────────────────

it('preview-code rejects missing entity_type', function (): void {
    $this->actingAs(previewOnlyUser())
        ->postJson(route('code-rules.preview-code'), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['entity_type']);
});

// ── Test 11: validate entity_type must be a valid enum value ─────────────────

it('preview-code rejects invalid entity_type', function (): void {
    $this->actingAs(previewOnlyUser())
        ->postJson(route('code-rules.preview-code'), [
            'entity_type' => 'not_a_real_entity',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['entity_type']);
});

// ── Test 12: employees.manage permission can access preview ──────────────────

it('user with employees.manage can access preview-code', function (): void {
    makeCodeRule(CodeRuleEntityType::Employee->value, 'EMP', '{PREFIX}-{YEAR}-{SEQUENCE}', 6);

    $user = User::factory()->create();
    $user->givePermissionTo('employees.manage');

    $this->actingAs($user)
        ->postJson(route('code-rules.preview-code'), [
            'entity_type' => CodeRuleEntityType::Employee->value,
        ])
        ->assertOk();
});

// ── Test 13: organizations.manage permission can access preview ───────────────

it('user with organizations.manage can access preview-code', function (): void {
    makeCodeRule(CodeRuleEntityType::Organization->value, 'ORG', '{PREFIX}-{YEAR}-{SEQUENCE}');

    $user = User::factory()->create();
    $user->givePermissionTo('organizations.manage');

    $this->actingAs($user)
        ->postJson(route('code-rules.preview-code'), [
            'entity_type' => CodeRuleEntityType::Organization->value,
        ])
        ->assertOk();
});

// ── Test 14: organization type context is passed correctly ────────────────────

it('preview-code accepts context with organization_type_id', function (): void {
    $orgType = OrganizationType::query()->create([
        'code' => 'dept',
        'name_en' => 'Department',
        'is_active' => true,
    ]);

    makeCodeRule(CodeRuleEntityType::Organization->value, 'ORG', '{PREFIX}-{YEAR}-{SEQUENCE}');

    $this->actingAs(previewOnlyUser())
        ->postJson(route('code-rules.preview-code'), [
            'entity_type' => CodeRuleEntityType::Organization->value,
            'context' => ['organization_type_id' => $orgType->id],
        ])
        ->assertOk()
        ->assertJsonPath('error', null);
});

// ── Test 15: response includes manual_override_allowed flag ──────────────────

it('preview-code response includes correct manual_override_allowed flag', function (): void {
    makeCodeRule(CodeRuleEntityType::Organization->value, 'ORG', '{PREFIX}-{YEAR}-{SEQUENCE}');

    $this->actingAs(previewOnlyUser())
        ->postJson(route('code-rules.preview-code'), [
            'entity_type' => CodeRuleEntityType::Organization->value,
        ])
        ->assertOk()
        ->assertJsonPath('manual_override_allowed', false);
});

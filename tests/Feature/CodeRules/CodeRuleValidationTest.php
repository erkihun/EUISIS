<?php

declare(strict_types=1);

use App\Enums\CodeRuleEntityType;
use App\Enums\CodeRuleResetFrequency;
use App\Models\CodeRule;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    foreach ([
        'code-rules.viewAny',
        'code-rules.view',
        'code-rules.create',
        'code-rules.update',
        'code-rules.archive',
        'code-rules.restore',
        'code-rules.preview',
        'code-rules.generate',
        'code-rules.export',
        'code-rules.manageOverrides',
    ] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    Role::findOrCreate('Super Admin', 'web')->syncPermissions(Permission::all());
});

function validationSuperAdmin(): User
{
    $user = User::factory()->create();
    $user->assignRole('Super Admin');

    return $user;
}

function basePayload(array $overrides = []): array
{
    return array_merge([
        'entity_type' => CodeRuleEntityType::Employee->value,
        'scope_type' => null,
        'scope_id' => null,
        'name_en' => 'Test Rule',
        'prefix' => 'TST',
        'suffix' => null,
        'format' => '{PREFIX}-{YEAR}-{SEQUENCE_PADDED}',
        'separator' => '-',
        'sequence_length' => 4,
        'next_number' => 1,
        'reset_frequency' => CodeRuleResetFrequency::Never->value,
        'year_format' => 'Y',
        'is_active' => true,
        'allow_manual_override' => false,
        'require_approval_for_override' => true,
    ], $overrides);
}

it('accepts format with sequence_padded', function (): void {
    $user = validationSuperAdmin();

    $this->actingAs($user)
        ->post(route('code-rules.store'), basePayload([
            'format' => '{PREFIX}-{YEAR}-{SEQUENCE_PADDED}',
        ]))
        ->assertRedirect();
});

it('accepts format with org_type_prefix and year', function (): void {
    $user = validationSuperAdmin();

    $this->actingAs($user)
        ->post(route('code-rules.store'), basePayload([
            'format' => '{ORG_TYPE_PREFIX}-{YEAR}-{SEQUENCE_PADDED}',
            'prefix' => null,
        ]))
        ->assertRedirect();
});

it('rejects format without sequence token', function (): void {
    $user = validationSuperAdmin();

    $this->actingAs($user)
        ->post(route('code-rules.store'), basePayload([
            'format' => '{PREFIX}-{YEAR}',
        ]))
        ->assertSessionHasErrors('format');
});

it('rejects format with unknown token', function (): void {
    $user = validationSuperAdmin();

    $this->actingAs($user)
        ->post(route('code-rules.store'), basePayload([
            'format' => '{PREFIX}-{UNKNOWN_TOKEN_XYZ}-{SEQUENCE_PADDED}',
        ]))
        ->assertSessionHasErrors('format');
});

it('accepts format with sequence (original token)', function (): void {
    $user = validationSuperAdmin();

    $this->actingAs($user)
        ->post(route('code-rules.store'), basePayload([
            'format' => '{PREFIX}-{SEQUENCE}',
        ]))
        ->assertRedirect();
});

it('accepts a random-only format without a sequence token', function (): void {
    $user = validationSuperAdmin();

    $this->actingAs($user)
        ->post(route('code-rules.store'), basePayload([
            'format' => 'EMP-{RAND_6}',
        ]))
        ->assertRedirect();

    expect(CodeRule::query()->where('format', 'EMP-{RAND_6}')->exists())->toBeTrue();
});

it('accepts all core tokens in format', function (): void {
    $user = validationSuperAdmin();

    $this->actingAs($user)
        ->post(route('code-rules.store'), basePayload([
            'format' => '{PREFIX}-{YEAR}-{MONTH}-{DAY}-{SEQUENCE_PADDED}',
        ]))
        ->assertRedirect();
});

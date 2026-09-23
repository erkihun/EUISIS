<?php

declare(strict_types=1);

use App\Enums\CodeRuleEntityType;
use App\Enums\CodeRuleResetFrequency;
use App\Models\CodeRule;
use App\Models\OrganizationType;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    foreach ([
        'code-rules.viewAny',
        'code-rules.view',
        'code-rules.create',
        'code-rules.update',
        'code-rules.preview',
        'code-rules.generate',
    ] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    Role::findOrCreate('Super Admin', 'web')->syncPermissions(Permission::all());
});

function newPreviewSuperAdmin(): User
{
    $user = User::factory()->create();
    $user->assignRole('Super Admin');

    return $user;
}

function previewPayload(array $overrides = []): array
{
    return array_merge([
        'entity_type' => CodeRuleEntityType::Employee->value,
        'prefix' => 'EMP',
        'suffix' => null,
        'format' => '{PREFIX}-{YEAR}-{SEQUENCE_PADDED}',
        'separator' => '-',
        'sequence_length' => 4,
        'next_number' => 1,
        'reset_frequency' => CodeRuleResetFrequency::Never->value,
        'year_format' => 'Y',
    ], $overrides);
}

it('preview resolves date tokens', function (): void {
    $user = newPreviewSuperAdmin();

    $this->actingAs($user)
        ->postJson(route('code-rules.preview'), previewPayload([
            'format' => '{PREFIX}-{YEAR}-{SEQUENCE_PADDED}',
            'sequence_length' => 4,
            'next_number' => 1,
        ]))
        ->assertOk()
        ->assertJsonPath('preview', 'EMP-2026-0001');
});

it('preview resolves sequence_padded', function (): void {
    $user = newPreviewSuperAdmin();

    $this->actingAs($user)
        ->postJson(route('code-rules.preview'), previewPayload([
            'format' => '{PREFIX}-{SEQUENCE_PADDED}',
            'sequence_length' => 6,
            'next_number' => 42,
        ]))
        ->assertOk()
        ->assertJsonPath('preview', 'EMP-000042');
});

it('preview resolves org type prefix with context', function (): void {
    $user = newPreviewSuperAdmin();

    $orgType = OrganizationType::query()->create([
        'code' => 'bureau_preview',
        'prefix' => 'BUR',
        'name_en' => 'Bureau Preview',
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->postJson(route('code-rules.preview'), previewPayload([
            'format' => '{ORG_TYPE_PREFIX}-{YEAR}-{SEQUENCE_PADDED}',
            'prefix' => null,
            'sequence_length' => 4,
            'next_number' => 1,
            'organization_type_id' => $orgType->id,
        ]))
        ->assertOk()
        ->assertJsonStructure(['preview'])
        ->assertJsonPath('preview', 'BUR-2026-0001');
});

it('preview does not increment next_number', function (): void {
    $user = newPreviewSuperAdmin();

    $rule = CodeRule::query()->create([
        'entity_type' => CodeRuleEntityType::Organization->value,
        'name_en' => 'No Increment Rule',
        'prefix' => 'ORG',
        'format' => '{PREFIX}-{YEAR}-{SEQUENCE_PADDED}',
        'separator' => '-',
        'sequence_length' => 4,
        'next_number' => 7,
        'reset_frequency' => CodeRuleResetFrequency::Never,
        'year_format' => 'Y',
        'is_active' => true,
        'allow_manual_override' => false,
        'require_approval_for_override' => true,
        'active_scope_key' => CodeRule::buildActiveScopeKey(CodeRuleEntityType::Organization),
    ]);

    $this->actingAs($user)
        ->postJson(route('code-rules.preview'), previewPayload([
            'entity_type' => CodeRuleEntityType::Organization->value,
            'format' => '{PREFIX}-{YEAR}-{SEQUENCE_PADDED}',
            'next_number' => 7,
        ]));

    // The rule's next_number must NOT have changed
    expect($rule->fresh()->next_number)->toBe(7);
});

it('preview resolves rand_8 to an eight-digit code', function (): void {
    $user = newPreviewSuperAdmin();

    $response = $this->actingAs($user)
        ->postJson(route('code-rules.preview'), previewPayload([
            'format' => 'AAC-{RAND_8}',
        ]))
        ->assertOk();

    expect($response->json('preview'))->toMatch('/^AAC-\d{8}$/');
});

it('preview uses the same format validation as create and update', function (): void {
    $user = newPreviewSuperAdmin();

    $this->actingAs($user)
        ->postJson(route('code-rules.preview'), previewPayload([
            'format' => '{PREFIX}-{UNKNOWN_TOKEN}',
            'sequence_length' => 11,
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['format', 'sequence_length']);
});

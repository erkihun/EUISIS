<?php

declare(strict_types=1);

use App\Actions\CodeRules\GenerateCodeAction;
use App\Actions\IdCards\ApproveCardRequestAction;
use App\Enums\AssignmentStatus;
use App\Enums\AuditEventType;
use App\Enums\CardRequestStatus;
use App\Enums\CardRequestType;
use App\Enums\CodeRuleEntityType;
use App\Enums\CodeRuleResetFrequency;
use App\Enums\EmployeeStatus;
use App\Enums\OrganizationStatus;
use App\Models\CardRequest;
use App\Models\CodeRule;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\Occupation;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Models\User;
use App\Services\CodeGeneration\CodeGeneratorService;
use Inertia\Testing\AssertableInertia;
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
        'organizations.manage',
        'employees.manage',
        'positions.create',
    ] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    Role::findOrCreate('Super Admin', 'web')->syncPermissions(Permission::all());
});

function superAdminUser(): User
{
    $user = User::factory()->create();
    $user->assignRole('Super Admin');

    return $user;
}

function createCodeRule(array $overrides = []): CodeRule
{
    $attributes = array_merge([
        'entity_type' => CodeRuleEntityType::Organization->value,
        'scope_type' => null,
        'scope_id' => null,
        'name_en' => 'Organization Code',
        'name_am' => null,
        'prefix' => 'ORG',
        'suffix' => null,
        'format' => '{PREFIX}-{YEAR}-{SEQUENCE}',
        'separator' => '-',
        'sequence_length' => 4,
        'next_number' => 1,
        'reset_frequency' => CodeRuleResetFrequency::Never,
        'year_format' => 'Y',
        'is_active' => true,
        'allow_manual_override' => false,
        'require_approval_for_override' => true,
        'description_en' => null,
        'description_am' => null,
    ], $overrides);

    if (($attributes['is_active'] ?? true) === false) {
        $attributes['active_scope_key'] = null;
    } elseif (! array_key_exists('active_scope_key', $overrides)) {
        $attributes['active_scope_key'] = CodeRule::buildActiveScopeKey(
            $attributes['entity_type'],
            $attributes['scope_type'] ?? null,
            $attributes['scope_id'] ?? null,
        );
    }

    return CodeRule::query()->create($attributes);
}

function makeOrganizationType(string $code = 'dept', ?string $prefix = null): OrganizationType
{
    return OrganizationType::query()->create([
        'code' => $code,
        'prefix' => $prefix,
        'name_en' => 'Department',
        'is_active' => true,
    ]);
}

function makeOrganization(OrganizationType $type, string $code = 'ORG-BASE-01'): Organization
{
    return Organization::query()->create([
        'organization_type_id' => $type->id,
        'code' => $code,
        'name_en' => 'Base Organization',
        'status' => OrganizationStatus::Active,
        'effective_from' => now()->toDateString(),
    ]);
}

it('denies index access without code rules permission', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('code-rules.index'))
        ->assertForbidden();
});

it('allows super admin to access the code rules index', function (): void {
    $this->actingAs(superAdminUser())
        ->get(route('code-rules.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('CodeRules/Index')
            ->has('can.create'));
});

it('creates a code rule', function (): void {
    $user = superAdminUser();

    $this->actingAs($user)
        ->post(route('code-rules.store'), [
            'entity_type' => CodeRuleEntityType::Employee->value,
            'scope_type' => null,
            'scope_id' => null,
            'name_en' => 'Employee Number',
            'name_am' => 'የሰራተኛ ቁጥር',
            'prefix' => 'EMP',
            'suffix' => null,
            'format' => '{PREFIX}-{YEAR}-{SEQUENCE}',
            'separator' => '-',
            'sequence_length' => 6,
            'next_number' => 1,
            'reset_frequency' => CodeRuleResetFrequency::Never->value,
            'year_format' => 'Y',
            'is_active' => true,
            'allow_manual_override' => false,
            'require_approval_for_override' => true,
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('code_rules', [
        'entity_type' => CodeRuleEntityType::Employee->value,
        'name_en' => 'Employee Number',
        'active_scope_key' => CodeRule::buildActiveScopeKey(CodeRuleEntityType::Employee),
    ]);
});

it('validates required entity type and sequence token', function (): void {
    $user = superAdminUser();

    $this->actingAs($user)
        ->post(route('code-rules.store'), [
            'entity_type' => '',
            'name_en' => 'Broken Rule',
            'format' => '{PREFIX}-{YEAR}',
            'separator' => '-',
            'sequence_length' => 4,
            'next_number' => 1,
            'reset_frequency' => CodeRuleResetFrequency::Never->value,
            'is_active' => true,
            'allow_manual_override' => false,
            'require_approval_for_override' => true,
        ])
        ->assertSessionHasErrors(['entity_type', 'format']);
});

it('rejects duplicate active rule for the same entity and scope', function (): void {
    $user = superAdminUser();
    createCodeRule();

    $this->actingAs($user)
        ->post(route('code-rules.store'), [
            'entity_type' => CodeRuleEntityType::Organization->value,
            'scope_type' => null,
            'scope_id' => null,
            'name_en' => 'Another Organization Code',
            'format' => '{PREFIX}-{YEAR}-{SEQUENCE}',
            'separator' => '-',
            'sequence_length' => 4,
            'next_number' => 1,
            'reset_frequency' => CodeRuleResetFrequency::Never->value,
            'is_active' => true,
            'allow_manual_override' => false,
            'require_approval_for_override' => true,
        ])
        ->assertSessionHasErrors('entity_type');
});

it('returns a preview for a code rule', function (): void {
    $user = superAdminUser();

    $this->actingAs($user)
        ->postJson(route('code-rules.preview'), [
            'entity_type' => CodeRuleEntityType::Organization->value,
            'prefix' => 'ORG',
            'format' => '{PREFIX}-{YEAR}-{SEQUENCE}',
            'separator' => '-',
            'sequence_length' => 4,
            'next_number' => 1,
            'reset_frequency' => CodeRuleResetFrequency::Never->value,
            'year_format' => 'Y',
        ])
        ->assertOk()
        ->assertJsonPath('preview', 'ORG-2026-0001');
});

it('supports organization type prefix token in code generation previews', function (): void {
    $type = makeOrganizationType('bureau', 'bur');
    $rule = createCodeRule([
        'entity_type' => CodeRuleEntityType::Organization->value,
        'prefix' => null,
        'format' => '{ORG_TYPE_PREFIX}-{SEQUENCE}',
        'sequence_length' => 4,
        'active_scope_key' => null,
    ]);

    $preview = app(CodeGeneratorService::class)->preview($rule, [
        'organization_type_id' => $type->id,
    ]);

    expect($preview)->toBe('BUR-0001');
});

it('writes an audit log when a code rule is updated', function (): void {
    $user = superAdminUser();
    $rule = createCodeRule();

    $this->actingAs($user)
        ->patch(route('code-rules.update', $rule), [
            'entity_type' => CodeRuleEntityType::Organization->value,
            'scope_type' => null,
            'scope_id' => null,
            'name_en' => 'Organization Code Updated',
            'name_am' => null,
            'prefix' => 'ORG',
            'suffix' => null,
            'format' => '{PREFIX}-{YEAR}-{SEQUENCE}',
            'separator' => '-',
            'sequence_length' => 5,
            'next_number' => 4,
            'reset_frequency' => CodeRuleResetFrequency::Never->value,
            'year_format' => 'Y',
            'is_active' => true,
            'allow_manual_override' => false,
            'require_approval_for_override' => true,
        ])
        ->assertRedirect(route('code-rules.show', $rule));

    $this->assertDatabaseHas('audit_logs', [
        'event_type' => AuditEventType::CodeRuleUpdated->value,
        'auditable_id' => $rule->id,
    ]);
});

it('deletes and restores a code rule', function (): void {
    $user = superAdminUser();
    $rule = createCodeRule();

    $this->actingAs($user)
        ->post(route('code-rules.archive', $rule))
        ->assertRedirect();

    expect(CodeRule::query()->withTrashed()->find($rule->id)->deleted_at)->not->toBeNull();

    $this->actingAs($user)
        ->post(route('code-rules.restore', $rule))
        ->assertRedirect();

    expect($rule->fresh()->is_active)->toBeTrue()
        ->and($rule->fresh()->deleted_at)->toBeNull();
});

it('blocks restoring a code rule when another active rule already exists for the same scope', function (): void {
    $user = superAdminUser();
    $archived = createCodeRule(['is_active' => false, 'active_scope_key' => null]);
    createCodeRule(['name_en' => 'Current Active Rule']);

    $this->actingAs($user)
        ->from(route('code-rules.index'))
        ->post(route('code-rules.restore', $archived))
        ->assertRedirect(route('code-rules.index'))
        ->assertSessionHasErrors('entity_type');
});

it('increments next number and returns unique generated codes', function (): void {
    $user = superAdminUser();
    createCodeRule([
        'entity_type' => CodeRuleEntityType::Position->value,
        'name_en' => 'Position Code',
        'prefix' => 'POS',
        'format' => '{PREFIX}-{SEQUENCE}',
        'active_scope_key' => CodeRule::buildActiveScopeKey(CodeRuleEntityType::Position),
    ]);

    $action = app(GenerateCodeAction::class);

    $first = $action->execute(CodeRuleEntityType::Position, [], $user, null, 'job_position_code');
    $second = $action->execute(CodeRuleEntityType::Position, [], $user, null, 'job_position_code');

    expect($first)->not()->toBe($second)
        ->and(CodeRule::query()->where('entity_type', CodeRuleEntityType::Position->value)->firstOrFail()->next_number)->toBe(3);
});

it('blocks manual override when the rule does not allow it', function (): void {
    $user = superAdminUser();
    createCodeRule([
        'entity_type' => CodeRuleEntityType::OrganizationType->value,
        'name_en' => 'Organization Type Code',
        'prefix' => 'OT',
        'format' => '{PREFIX}-{SEQUENCE}',
        'active_scope_key' => CodeRule::buildActiveScopeKey(CodeRuleEntityType::OrganizationType),
        'allow_manual_override' => false,
    ]);

    $this->actingAs($user)
        ->post(route('organization-types.store'), [
            'code' => 'MANUAL-CODE',
            'name_en' => 'Manual Type',
            'is_active' => true,
            'sort_order' => 1,
        ])
        ->assertSessionHasErrors('code');
});

it('generates organization code when omitted on create', function (): void {
    $user = superAdminUser();
    createCodeRule();
    $type = makeOrganizationType('bureau');

    $this->actingAs($user)
        ->post(route('organizations.store'), [
            'organization_type_id' => $type->id,
            'name_en' => 'Generated Organization',
            'status' => OrganizationStatus::Active->value,
        ])
        ->assertRedirect();

    $organization = Organization::query()->where('name_en', 'Generated Organization')->firstOrFail();

    expect($organization->code)->toStartWith('ORG-2026-');
});

it('generates employee number when omitted on create', function (): void {
    $user = superAdminUser();
    createCodeRule([
        'entity_type' => CodeRuleEntityType::Employee->value,
        'name_en' => 'Employee Number',
        'prefix' => 'EMP',
        'format' => '{PREFIX}-{YEAR}-{SEQUENCE}',
        'sequence_length' => 6,
        'active_scope_key' => CodeRule::buildActiveScopeKey(CodeRuleEntityType::Employee),
    ]);

    $type = makeOrganizationType('team');
    $organization = makeOrganization($type, 'ORG-HR-01');

    $this->actingAs($user)
        ->post(route('employees.store'), [
            'first_name' => 'Abel',
            'last_name' => 'Bekele',
            'status' => EmployeeStatus::Active->value,
            'organization_id' => $organization->id,
            'effective_from' => now()->toDateString(),
        ])
        ->assertRedirect();

    $employee = Employee::query()->where('full_name', 'Abel Bekele')->firstOrFail();

    expect($employee->employee_number)->toStartWith('EMP-2026-');
});

it('generates position code when omitted on create', function (): void {
    $user = superAdminUser();
    createCodeRule([
        'entity_type' => CodeRuleEntityType::Position->value,
        'name_en' => 'Position Code',
        'prefix' => 'POS',
        'format' => '{PREFIX}-{SEQUENCE}',
        'active_scope_key' => CodeRule::buildActiveScopeKey(CodeRuleEntityType::Position),
    ]);

    $type = makeOrganizationType('unit-test');
    $org = makeOrganization($type, 'ORG-POS-TEST');
    $unit = OrganizationUnit::query()->create([
        'organization_id' => $org->id,
        'unit_type' => 'department',
        'code' => 'UNIT-POS-01',
        'name_en' => 'Test Unit',
        'status' => 'active',
    ]);
    $occupation = (new Occupation)->forceFill([
        'code' => 'OCC-TEST-RULE-01',
        'name_en' => 'Test Occupation',
    ]);
    $occupation->save();

    $this->actingAs($user)
        ->post(route('positions.store'), [
            'title_en' => 'Systems Analyst',
            'old_code' => 'LEGACY-SA-01',
            'bpr_name' => 'Systems Analysis Specialist',
            'is_active' => true,
            'organization_unit_id' => $unit->id,
            'occupation_id' => $occupation->id,
        ])
        ->assertRedirect();

    $position = Position::query()->where('title_en', 'Systems Analyst')->firstOrFail();

    expect($position->job_position_code)->toStartWith('POS-')
        ->and($position->old_code)->toBe('LEGACY-SA-01')
        ->and($position->bpr_name)->toBe('Systems Analysis Specialist');
});

it('generates id card number from code rules during approval', function (): void {
    $user = superAdminUser();
    createCodeRule([
        'entity_type' => CodeRuleEntityType::IdCard->value,
        'name_en' => 'ID Card Number',
        'prefix' => 'IDC',
        'format' => '{PREFIX}-{YEAR}-{SEQUENCE}',
        'sequence_length' => 6,
        'active_scope_key' => CodeRule::buildActiveScopeKey(CodeRuleEntityType::IdCard),
    ]);

    $type = makeOrganizationType('office');
    $organization = makeOrganization($type, 'ORG-CARD-01');
    $position = Position::query()->create([
        'job_position_code' => 'POS-EXIST-01',
        'title_en' => 'Officer',
        'is_active' => true,
    ]);
    $employee = Employee::query()->create([
        'employee_number' => 'EMP-SEED-0001',
        'first_name' => 'Card',
        'last_name' => 'Holder',
        'full_name' => 'Card Holder',
        'status' => EmployeeStatus::Active,
    ]);

    $assignment = EmployeeAssignment::query()->create([
        'employee_id' => $employee->id,
        'organization_id' => $organization->id,
        'position_id' => $position->id,
        'assignment_status' => AssignmentStatus::Active,
        'effective_from' => now()->toDateString(),
        'is_current' => true,
    ]);
    $employee->update(['current_assignment_id' => $assignment->id]);

    $cardRequest = CardRequest::query()->create([
        'employee_id' => $employee->id,
        'requested_by' => $user->id,
        'request_type' => CardRequestType::New,
        'status' => CardRequestStatus::Submitted,
        'submitted_at' => now(),
    ]);

    $result = app(ApproveCardRequestAction::class)->execute($cardRequest, $user);

    expect($result['card']->card_number)->toStartWith('IDC-2026-');
});

it('documents translation files for code rules', function (): void {
    expect(file_exists(lang_path('en/code-rules.php')))->toBeTrue()
        ->and(file_exists(lang_path('am/code-rules.php')))->toBeTrue()
        ->and(file_exists(resource_path('js/i18n/en/codeRules.ts')))->toBeTrue()
        ->and(file_exists(resource_path('js/i18n/am/codeRules.ts')))->toBeTrue();
});

<?php

declare(strict_types=1);

use App\Actions\CodeRules\GenerateCodeAction;
use App\Enums\CodeRuleEntityType;
use App\Enums\CodeRuleResetFrequency;
use App\Models\CodeRule;
use App\Models\CodeRuleSequence;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\User;
use App\Services\CodeGeneration\CodeGeneratorService;
use App\Services\CodeGeneration\CodeRuleTargetRegistry;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/*
 * Editing a rule changes only what the person changed; a typed code is held to
 * the same uniqueness as a generated one; every entity type with a known code
 * column is checked before a code is handed out.
 */

beforeEach(function (): void {
    app()->setLocale('en');

    foreach (['code-rules.viewAny', 'code-rules.view', 'code-rules.update'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    Role::findOrCreate('Super Admin', 'web')->syncPermissions(Permission::all());
});

function integrityAdmin(): User
{
    $user = User::factory()->create();
    $user->assignRole('Super Admin');

    return $user;
}

function integrityRule(array $overrides = []): CodeRule
{
    $attributes = $overrides + [
        'entity_type' => CodeRuleEntityType::OrganizationType->value,
        'name_en' => 'Organization Type Code',
        'prefix' => 'OT',
        'format' => '{PREFIX}-{SEQUENCE}',
        'separator' => '-',
        'sequence_length' => 4,
        'next_number' => 1,
        'initial_sequence_number' => 1,
        'reset_frequency' => CodeRuleResetFrequency::Never,
        'is_active' => true,
        'allow_manual_override' => true,
        'require_approval_for_override' => false,
    ];
    $attributes['active_scope_key'] = CodeRule::buildActiveScopeKey($attributes['entity_type']);

    return CodeRule::query()->create($attributes);
}

/** @return array<string, mixed> the edit form's payload for the rule */
function integrityPayload(CodeRule $rule, array $overrides = []): array
{
    $rule->refresh();

    return $overrides + [
        'entity_type' => $rule->entity_type->value,
        'scope_type' => null,
        'scope_id' => null,
        'name_en' => $rule->name_en,
        'prefix' => $rule->prefix,
        'format' => $rule->format,
        'separator' => $rule->separator,
        'sequence_length' => $rule->sequence_length,
        'next_number' => $rule->next_number,
        'reset_frequency' => $rule->reset_frequency->value,
        'is_active' => true,
        'allow_manual_override' => $rule->allow_manual_override,
        'require_approval_for_override' => $rule->require_approval_for_override,
    ];
}

function issueCodes(int $count): void
{
    foreach (range(1, $count) as $i) {
        app(GenerateCodeAction::class)->execute(CodeRuleEntityType::OrganizationType);
    }
}

it('leaves the restart number alone when only the rule name is edited', function (): void {
    $rule = integrityRule();
    issueCodes(3);

    $this->actingAs(integrityAdmin())
        ->patch(route('code-rules.update', $rule), integrityPayload($rule, ['name_en' => 'Type Code (renamed)']))
        ->assertSessionHasNoErrors();

    expect($rule->fresh()->initial_sequence_number)->toBe(1)
        ->and($rule->fresh()->next_number)->toBe(4)
        ->and(app(GenerateCodeAction::class)->execute(CodeRuleEntityType::OrganizationType))->toBe('OT-0004');
});

it('moves the live counter when the next number is raised', function (): void {
    $rule = integrityRule();
    issueCodes(2);

    $this->actingAs(integrityAdmin())
        ->patch(route('code-rules.update', $rule), integrityPayload($rule, ['next_number' => 500]))
        ->assertSessionHasNoErrors();

    expect(app(GenerateCodeAction::class)->execute(CodeRuleEntityType::OrganizationType))->toBe('OT-0500');
});

it('refuses a next number below the codes already issued', function (): void {
    $rule = integrityRule();
    issueCodes(5);

    $this->actingAs(integrityAdmin())
        ->patch(route('code-rules.update', $rule), integrityPayload($rule, ['next_number' => 2]))
        ->assertSessionHasErrors(['next_number' => __('code-rules.next_number_below_reached', ['number' => 6, 'last' => 5])]);

    expect(CodeRuleSequence::query()->where('code_rule_id', $rule->id)->value('next_number'))->toBe(6);
});

it('keeps the record type of a rule that has issued codes', function (): void {
    $rule = integrityRule();
    issueCodes(1);

    $this->actingAs(integrityAdmin())
        ->patch(route('code-rules.update', $rule), integrityPayload($rule, ['entity_type' => CodeRuleEntityType::OrganizationUnitType->value]))
        ->assertSessionHasErrors(['entity_type' => __('code-rules.entity_type_locked')]);

    expect($rule->fresh()->entity_type)->toBe(CodeRuleEntityType::OrganizationType);
});

it('lets an unused rule change its record type', function (): void {
    $rule = integrityRule();

    $this->actingAs(integrityAdmin())
        ->patch(route('code-rules.update', $rule), integrityPayload($rule, ['entity_type' => CodeRuleEntityType::OrganizationUnitType->value]))
        ->assertSessionHasNoErrors();

    expect($rule->fresh()->entity_type)->toBe(CodeRuleEntityType::OrganizationUnitType);
});

it('refuses a typed code another record already holds', function (): void {
    integrityRule();
    OrganizationType::query()->create(['code' => 'OT-0007', 'name_en' => 'Existing']);

    try {
        app(GenerateCodeAction::class)->execute(CodeRuleEntityType::OrganizationType, [], null, 'OT-0007');
        $this->fail('A taken code was accepted.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['code'][0])->toBe(__('code-rules.code_already_in_use', ['code' => 'OT-0007']));
    }

    expect(app(GenerateCodeAction::class)->execute(CodeRuleEntityType::OrganizationType, [], null, 'OT-0008'))->toBe('OT-0008');
});

it('accepts a typed unit code that another organization already uses', function (): void {
    $type = OrganizationType::query()->create(['code' => 'BUREAU', 'name_en' => 'Bureau']);
    $other = Organization::query()->create([
        'organization_type_id' => $type->id, 'code' => 'ORG-B', 'name_en' => 'Other', 'name_am' => 'ሌላ', 'status' => 'active',
    ]);
    OrganizationUnit::query()->create([
        'organization_id' => $other->id, 'unit_type' => 'department', 'code' => 'U-ROOT', 'name_en' => 'Head Office', 'status' => 'active',
    ]);

    expect(app(GenerateCodeAction::class)->execute(CodeRuleEntityType::OrganizationUnit, [], null, 'U-ROOT'))->toBe('U-ROOT');
});

it('checks every entity type with a known code column before handing out a code', function (): void {
    $registry = app(CodeRuleTargetRegistry::class);

    foreach (array_keys($registry->all()) as $entityType) {
        expect($registry->collisionCheck($entityType))->not->toBeNull("{$entityType} has no collision check");
    }
});

it('cuts a long code by characters, never inside an Ethiopic letter', function (): void {
    $rule = integrityRule([
        'prefix' => str_repeat('ቢሮ', 10),
        'format' => '{PREFIX}{PREFIX}{PREFIX}{PREFIX}{PREFIX}{PREFIX}-{SEQUENCE}',
    ]);

    $code = app(CodeGeneratorService::class)->formatCode($rule, [], 1);

    expect(mb_check_encoding($code, 'UTF-8'))->toBeTrue()
        ->and(mb_strlen($code))->toBe(100);
});

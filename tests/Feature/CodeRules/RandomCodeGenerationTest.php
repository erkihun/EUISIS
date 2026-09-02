<?php

declare(strict_types=1);

use App\Actions\Audit\WriteAuditLogAction;
use App\Actions\CodeRules\GenerateCodeAction;
use App\Enums\CodeRuleEntityType;
use App\Enums\CodeRuleResetFrequency;
use App\Enums\CodeRuleScopeStrategy;
use App\Enums\EmployeeStatus;
use App\Models\CodeRule;
use App\Models\Employee;
use App\Models\OrganizationType;
use App\Models\User;
use App\Services\CodeGeneration\CodeFormatTokenResolver;
use App\Services\CodeGeneration\CodeGeneratorService;
use App\Services\CodeGeneration\CodeRuleResolver;
use App\Services\CodeGeneration\PositionCodeContextResolver;
use App\Services\CodeGeneration\SequenceScopeResolver;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function randomEmployeeCodeRule(array $overrides = []): CodeRule
{
    return CodeRule::query()->create(array_merge([
        'entity_type' => CodeRuleEntityType::Employee->value,
        'scope_type' => null,
        'scope_id' => null,
        'name_en' => 'Random Employee Number',
        'prefix' => 'EMP',
        'format' => 'EMP-{RAND_6}',
        'separator' => '-',
        'sequence_length' => 6,
        'next_number' => 1,
        'initial_sequence_number' => 1,
        'sequence_scope_strategy' => CodeRuleScopeStrategy::Global,
        'sequence_scope_tokens' => [],
        'reset_frequency' => CodeRuleResetFrequency::Never,
        'year_format' => 'Y',
        'is_active' => true,
        'allow_manual_override' => false,
        'require_approval_for_override' => false,
        'active_scope_key' => CodeRule::buildActiveScopeKey(CodeRuleEntityType::Employee),
    ], $overrides));
}

it('formats employee codes with rand_6', function (): void {
    $code = app(CodeGeneratorService::class)->preview(randomEmployeeCodeRule());

    expect($code)->toMatch('/^EMP-\d{6}$/')
        ->and((int) substr($code, 4))->toBeGreaterThanOrEqual(100000)
        ->and((int) substr($code, 4))->toBeLessThanOrEqual(999999);
});

it('retries a duplicate random code against the target code column', function (): void {
    Employee::query()->create([
        'employee_number' => 'EMP-111111',
        'first_name' => 'Existing',
        'last_name' => 'Employee',
        'full_name' => 'Existing Employee',
        'status' => EmployeeStatus::Active,
    ]);

    $resolver = new class(app(PositionCodeContextResolver::class), ['111111', '222222']) extends CodeFormatTokenResolver
    {
        public int $calls = 0;

        /** @param list<string> $values */
        public function __construct(PositionCodeContextResolver $positionResolver, private array $values)
        {
            parent::__construct($positionResolver);
        }

        public function resolveAll(CodeRule $rule, array $context, Carbon $now): array
        {
            $this->calls++;

            return ['{RAND_6}' => array_shift($this->values) ?? '222222'];
        }
    };

    $generator = new CodeGeneratorService($resolver, new SequenceScopeResolver($resolver));
    $generated = $generator->generate(randomEmployeeCodeRule());

    expect($generated)->toBe('EMP-222222')
        ->and($resolver->calls)->toBe(2);
});

it('does not reuse a random code held by a soft-deleted record', function (): void {
    $organizationType = OrganizationType::query()->create([
        'code' => 'TYPE-111111',
        'name_en' => 'Archived type',
        'is_active' => false,
    ]);
    $organizationType->delete();

    $resolver = new class(app(PositionCodeContextResolver::class), ['111111', '222222']) extends CodeFormatTokenResolver
    {
        /** @param list<string> $values */
        public function __construct(PositionCodeContextResolver $positionResolver, private array $values)
        {
            parent::__construct($positionResolver);
        }

        public function resolveAll(CodeRule $rule, array $context, Carbon $now): array
        {
            return ['{RAND_6}' => array_shift($this->values) ?? '222222'];
        }
    };

    $rule = randomEmployeeCodeRule([
        'entity_type' => CodeRuleEntityType::OrganizationType->value,
        'name_en' => 'Random Organization Type Code',
        'format' => 'TYPE-{RAND_6}',
        'active_scope_key' => CodeRule::buildActiveScopeKey(CodeRuleEntityType::OrganizationType),
    ]);

    $generated = (new CodeGeneratorService($resolver, new SequenceScopeResolver($resolver)))->generate($rule);

    expect($generated)->toBe('TYPE-222222');
});

it('stops after twenty duplicate random codes with a validation error', function (): void {
    Employee::query()->create([
        'employee_number' => 'EMP-111111',
        'first_name' => 'Existing',
        'last_name' => 'Employee',
        'full_name' => 'Existing Employee',
        'status' => EmployeeStatus::Active,
    ]);

    $resolver = new class(app(PositionCodeContextResolver::class)) extends CodeFormatTokenResolver
    {
        public int $calls = 0;

        public function resolveAll(CodeRule $rule, array $context, Carbon $now): array
        {
            $this->calls++;

            return ['{RAND_6}' => '111111'];
        }
    };

    $action = new GenerateCodeAction(
        app(CodeRuleResolver::class),
        new CodeGeneratorService($resolver, new SequenceScopeResolver($resolver)),
        app(WriteAuditLogAction::class),
    );

    try {
        $action->execute(
            CodeRuleEntityType::Employee,
            resolvedRule: randomEmployeeCodeRule(),
            field: 'employee_number',
        );

        $this->fail('Expected duplicate random code validation to fail.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['employee_number'])->toBe([
            __('code-rules.random_code_duplicate'),
        ]);
    }

    expect($resolver->calls)->toBe(20);
});

it('does not regenerate an employee number during a normal update', function (): void {
    Permission::findOrCreate('employees.manage', 'web');
    $role = Role::findOrCreate('Super Admin', 'web');
    $role->givePermissionTo('employees.manage');

    $user = User::factory()->create();
    $user->assignRole($role);

    $employee = Employee::query()->create([
        'employee_number' => 'EMP-483920',
        'first_name' => 'Original',
        'last_name' => 'Employee',
        'full_name' => 'Original Employee',
        'status' => EmployeeStatus::Active,
    ]);

    $this->actingAs($user)
        ->patch(route('employees.update', $employee), [
            'first_name' => 'Updated',
            'middle_name' => null,
            'last_name' => 'Employee',
            'status' => EmployeeStatus::Active->value,
        ])
        ->assertRedirect();

    expect($employee->refresh()->employee_number)->toBe('EMP-483920');
});

<?php

declare(strict_types=1);

use App\Enums\CodeRuleEntityType;
use App\Enums\CodeRuleResetFrequency;
use App\Enums\OrganizationStatus;
use App\Exceptions\MissingTokenContextException;
use App\Models\CodeRule;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Services\CodeGeneration\CodeFormatTokenResolver;
use Illuminate\Support\Carbon;

function makeTestRule(array $overrides = []): CodeRule
{
    $rule = new CodeRule(array_merge([
        'entity_type' => CodeRuleEntityType::Organization->value,
        'name_en' => 'Test Rule',
        'prefix' => 'ORG',
        'suffix' => null,
        'format' => '{PREFIX}-{YEAR}-{SEQUENCE_PADDED}',
        'separator' => '-',
        'sequence_length' => 4,
        'next_number' => 1,
        'reset_frequency' => CodeRuleResetFrequency::Never,
        'year_format' => 'Y',
        'is_active' => true,
        'allow_manual_override' => false,
        'require_approval_for_override' => true,
    ], $overrides));

    $rule->next_number = (int) ($overrides['next_number'] ?? 1);
    $rule->sequence_length = (int) ($overrides['sequence_length'] ?? 4);

    return $rule;
}

beforeEach(function (): void {
    $this->resolver = app(CodeFormatTokenResolver::class);
    $this->now = Carbon::parse('2026-05-14 09:30:45');
});

it('resolves date tokens without context', function (): void {
    $rule = makeTestRule();
    $context = ['_sequence_number' => 1];

    expect($this->resolver->resolveToken('YEAR', $rule, $context, $this->now))->toBe('2026');
    expect($this->resolver->resolveToken('YEAR_SHORT', $rule, $context, $this->now))->toBe('26');
    expect($this->resolver->resolveToken('MONTH', $rule, $context, $this->now))->toBe('05');
    expect($this->resolver->resolveToken('MONTH_NAME', $rule, $context, $this->now))->toBe('MAY');
    expect($this->resolver->resolveToken('DAY', $rule, $context, $this->now))->toBe('14');
    expect($this->resolver->resolveToken('DATE', $rule, $context, $this->now))->toBe('20260514');
    expect($this->resolver->resolveToken('TIME', $rule, $context, $this->now))->toBe('093045');
    expect($this->resolver->resolveToken('TIMESTAMP', $rule, $context, $this->now))->toBe('20260514093045');
});

it('resolves prefix from rule', function (): void {
    $rule = makeTestRule(['prefix' => 'EMP']);
    $context = ['_sequence_number' => 5];

    expect($this->resolver->resolveToken('PREFIX', $rule, $context, $this->now))->toBe('EMP');
});

it('resolves sequence_padded with padding', function (): void {
    $rule = makeTestRule(['sequence_length' => 6]);
    $context = ['_sequence_number' => 42];

    $result = $this->resolver->resolveToken('SEQUENCE_PADDED', $rule, $context, $this->now);

    expect($result)->toBe('000042');
});

it('resolves sequence token with padding', function (): void {
    $rule = makeTestRule(['sequence_length' => 4]);
    $context = ['_sequence_number' => 7];

    $result = $this->resolver->resolveToken('SEQUENCE', $rule, $context, $this->now);

    expect($result)->toBe('0007');
});

it('resolves rand_6 as a numeric six digit value in range', function (): void {
    $result = $this->resolver->resolveToken('RAND_6', makeTestRule(), [], $this->now);

    expect($result)->toMatch('/^\d{6}$/')
        ->and((int) $result)->toBeGreaterThanOrEqual(100000)
        ->and((int) $result)->toBeLessThanOrEqual(999999);
});

it('resolves year_short correctly', function (): void {
    $rule = makeTestRule();
    $context = ['_sequence_number' => 1];
    $now = Carbon::parse('2026-01-01');

    expect($this->resolver->resolveToken('YEAR_SHORT', $rule, $context, $now))->toBe('26');
});

it('resolves fiscal_year as gregorian year', function (): void {
    $rule = makeTestRule();
    $context = ['_sequence_number' => 1];

    expect($this->resolver->resolveToken('FISCAL_YEAR', $rule, $context, $this->now))->toBe('2026');
});

it('throws missing context exception for org_code without org_id', function (): void {
    $rule = makeTestRule();
    $context = ['_sequence_number' => 1];

    expect(fn () => $this->resolver->resolveToken('ORG_CODE', $rule, $context, $this->now))
        ->toThrow(MissingTokenContextException::class);
});

it('resolves org_type_prefix with organization_type_id context', function (): void {
    $orgType = OrganizationType::query()->create([
        'code' => 'bureau_test',
        'prefix' => 'BUR',
        'name_en' => 'Bureau Test',
        'is_active' => true,
    ]);

    $rule = makeTestRule();
    $context = [
        '_sequence_number' => 1,
        'organization_type_id' => $orgType->id,
    ];

    $result = $this->resolver->resolveToken('ORG_TYPE_PREFIX', $rule, $context, $this->now);

    expect($result)->toBe('BUR');
});

it('resolves org_type_prefix via organization context', function (): void {
    $orgType = OrganizationType::query()->create([
        'code' => 'sector_test',
        'prefix' => 'SEC',
        'name_en' => 'Sector Test',
        'is_active' => true,
    ]);

    $org = Organization::query()->create([
        'organization_type_id' => $orgType->id,
        'code' => 'SEC-TEST-01',
        'name_en' => 'Test Sector',
        'status' => OrganizationStatus::Active,
        'effective_from' => now()->toDateString(),
    ]);

    $rule = makeTestRule();
    $context = [
        '_sequence_number' => 1,
        'organization_id' => $org->id,
    ];

    $result = $this->resolver->resolveToken('ORG_TYPE_PREFIX', $rule, $context, $this->now);

    expect($result)->toBe('SEC');
});

it('resolves custom tokens from context', function (): void {
    $rule = makeTestRule();
    $context = [
        '_sequence_number' => 1,
        'custom' => 'myval',
        'custom_1' => 'val1',
        'custom_2' => 'val2',
        'custom_3' => 'val3',
    ];

    expect($this->resolver->resolveToken('CUSTOM', $rule, $context, $this->now))->toBe('MYVAL');
    expect($this->resolver->resolveToken('CUSTOM_1', $rule, $context, $this->now))->toBe('VAL1');
    expect($this->resolver->resolveToken('CUSTOM_2', $rule, $context, $this->now))->toBe('VAL2');
    expect($this->resolver->resolveToken('CUSTOM_3', $rule, $context, $this->now))->toBe('VAL3');
});

it('sanitizes values to uppercase alphanumeric with allowed symbols', function (): void {
    expect($this->resolver->sanitize('hello world!'))->toBe('HELLOWORLD');
    expect($this->resolver->sanitize('test-123'))->toBe('TEST-123');
    expect($this->resolver->sanitize('foo/bar.baz_qux'))->toBe('FOO/BAR.BAZ_QUX');
    expect($this->resolver->sanitize(str_repeat('A', 60)))->toHaveLength(50);
});

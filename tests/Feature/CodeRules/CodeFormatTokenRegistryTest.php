<?php

declare(strict_types=1);

use App\Services\CodeGeneration\CodeFormatTokenRegistry;

beforeEach(function (): void {
    $this->registry = app(CodeFormatTokenRegistry::class);
});

it('returns all active tokens', function (): void {
    $active = $this->registry->active();

    expect($active)->not->toBeEmpty();

    foreach ($active as $tokenKey => $tokenDef) {
        expect($tokenDef['is_active'])->toBeTrue("Token {$tokenKey} should be active but is not.");
    }
});

it('has prefix token in core category', function (): void {
    $all = $this->registry->all();

    expect($all)->toHaveKey('PREFIX');
    expect($all['PREFIX']['category'])->toBe('Core');
    expect($all['PREFIX']['requires_context'])->toBeFalse();
    expect($all['PREFIX']['is_active'])->toBeTrue();
});

it('has year token in date_time category', function (): void {
    $all = $this->registry->all();

    expect($all)->toHaveKey('YEAR');
    expect($all['YEAR']['category'])->toBe('DateAndTime');
    expect($all['YEAR']['is_active'])->toBeTrue();
});

it('has org_type_prefix token that requires context', function (): void {
    $all = $this->registry->all();

    expect($all)->toHaveKey('ORG_TYPE_PREFIX');
    expect($all['ORG_TYPE_PREFIX']['requires_context'])->toBeTrue();
    expect($all['ORG_TYPE_PREFIX']['context_key'])->toBe('organization_id');
    expect($all['ORG_TYPE_PREFIX']['category'])->toBe('Organization');
});

it('does not include ethiopian_year as active', function (): void {
    $all = $this->registry->all();

    expect($all)->toHaveKey('ETHIOPIAN_YEAR');
    expect($all['ETHIOPIAN_YEAR']['is_active'])->toBeFalse();

    $active = $this->registry->active();
    expect($active)->not->toHaveKey('ETHIOPIAN_YEAR');
});

it('contains all expected categories', function (): void {
    $categories = collect($this->registry->all())
        ->pluck('category')
        ->unique()
        ->sort()
        ->values()
        ->toArray();

    expect($categories)->toContain('Core');
    expect($categories)->toContain('DateAndTime');
    expect($categories)->toContain('Organization');
    expect($categories)->toContain('Employee');
    expect($categories)->toContain('Position');
    expect($categories)->toContain('Service');
    expect($categories)->toContain('Location');
    expect($categories)->toContain('Workflow');
    expect($categories)->toContain('Custom');
});

it('returns frontend-safe payload without internal fields', function (): void {
    $frontend = $this->registry->forFrontend(activeOnly: true);

    expect($frontend)->not->toBeEmpty();

    foreach ($frontend as $item) {
        expect($item)->toHaveKeys(['token', 'label_en', 'label_am', 'description_en', 'description_am', 'category', 'requires_context', 'example', 'is_active']);
        expect($item)->not->toHaveKey('context_key');
    }
});

it('can check whether a token exists', function (): void {
    expect($this->registry->has('PREFIX'))->toBeTrue();
    expect($this->registry->has('SEQUENCE_PADDED'))->toBeTrue();
    expect($this->registry->has('NONEXISTENT_TOKEN'))->toBeFalse();
});

it('has sequence and sequence_padded both in core', function (): void {
    $all = $this->registry->all();

    expect($all)->toHaveKey('SEQUENCE');
    expect($all)->toHaveKey('SEQUENCE_PADDED');
    expect($all['SEQUENCE']['category'])->toBe('Core');
    expect($all['SEQUENCE_PADDED']['category'])->toBe('Core');
    expect($all['SEQUENCE']['is_active'])->toBeTrue();
    expect($all['SEQUENCE_PADDED']['is_active'])->toBeTrue();
});

it('publishes the secure six digit random token to the code rule UI', function (): void {
    $token = $this->registry->all()['RAND_6'];

    expect($token['category'])->toBe('Core')
        ->and($token['requires_context'])->toBeFalse()
        ->and($token['label_en'])->toBe('6-digit random number')
        ->and($token['example'])->toBe('EMP-{RAND_6} → EMP-483920')
        ->and($token['is_active'])->toBeTrue();
});

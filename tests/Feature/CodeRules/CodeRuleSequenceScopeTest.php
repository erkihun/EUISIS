<?php

declare(strict_types=1);

use App\Enums\CodeRuleEntityType;
use App\Enums\CodeRuleResetFrequency;
use App\Enums\CodeRuleScopeStrategy;
use App\Exceptions\MissingSequenceScopeContextException;
use App\Models\CodeRule;
use App\Models\CodeRuleSequence;
use App\Models\OrganizationType;
use App\Models\User;
use App\Services\CodeGeneration\CodeGeneratorService;
use App\Services\CodeGeneration\SequenceScopeResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

// ─── Helpers ──────────────────────────────────────────────────────────────────

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
        'code-rules.viewSequences',
        'code-rules.manageSequences',
        'code-rules.resetSequence',
    ] as $perm) {
        Permission::findOrCreate($perm, 'web');
    }

    Role::findOrCreate('Super Admin', 'web')->syncPermissions(Permission::all());
});

function scopeSuperAdmin(): User
{
    $user = User::factory()->create();
    $user->assignRole('Super Admin');

    return $user;
}

/** Create a CodeRule seeded directly (bypassing the HTTP layer). */
function makeScopeRule(array $overrides = []): CodeRule
{
    $attrs = array_merge([
        'entity_type' => CodeRuleEntityType::Organization->value,
        'scope_type' => null,
        'scope_id' => null,
        'name_en' => 'Scope Test Rule',
        'prefix' => 'ORG',
        'suffix' => null,
        'format' => '{PREFIX}-{YEAR}-{SEQUENCE_PADDED}',
        'separator' => '-',
        'sequence_length' => 4,
        'next_number' => 1,
        'initial_sequence_number' => 1,
        'sequence_scope_strategy' => CodeRuleScopeStrategy::Auto,
        'sequence_scope_tokens' => [],
        'reset_frequency' => CodeRuleResetFrequency::Never,
        'year_format' => 'Y',
        'is_active' => true,
        'allow_manual_override' => false,
        'require_approval_for_override' => true,
    ], $overrides);

    if (! array_key_exists('active_scope_key', $overrides)) {
        $attrs['active_scope_key'] = ($attrs['is_active'] ?? true)
            ? CodeRule::buildActiveScopeKey($attrs['entity_type'], $attrs['scope_type'] ?? null, $attrs['scope_id'] ?? null)
            : null;
    }

    return CodeRule::query()->create($attrs);
}

// ─── 1. Per-prefix independent counters ───────────────────────────────────────

it('gives each org-type prefix its own independent sequence counter', function (): void {
    OrganizationType::query()->create(['code' => 'BUR', 'prefix' => 'BUR', 'name_en' => 'Bureau', 'is_active' => true]);
    OrganizationType::query()->create(['code' => 'DEP', 'prefix' => 'DEP', 'name_en' => 'Department', 'is_active' => true]);

    $burType = OrganizationType::query()->where('code', 'BUR')->firstOrFail();
    $depType = OrganizationType::query()->where('code', 'DEP')->firstOrFail();

    // Use a format without date tokens so the scope key is purely ORG_TYPE_PREFIX=<value>
    $rule = makeScopeRule([
        'format' => '{ORG_TYPE_PREFIX}-{SEQUENCE_PADDED}',
        'prefix' => null,
        'active_scope_key' => CodeRule::buildActiveScopeKey(CodeRuleEntityType::Organization),
    ]);

    $gen = app(CodeGeneratorService::class);

    $bur1 = $gen->generate($rule, ['organization_type_id' => $burType->id]);
    $bur2 = $gen->generate($rule, ['organization_type_id' => $burType->id]);
    $dep1 = $gen->generate($rule, ['organization_type_id' => $depType->id]);

    expect($bur1)->toContain('BUR')
        ->and($bur2)->toContain('BUR')
        ->and($dep1)->toContain('DEP');

    // Each prefix starts at 1 and increments independently
    $burSeq = CodeRuleSequence::query()
        ->where('code_rule_id', $rule->id)
        ->where('sequence_scope_key', 'ORG_TYPE_PREFIX=BUR')
        ->firstOrFail();

    $depSeq = CodeRuleSequence::query()
        ->where('code_rule_id', $rule->id)
        ->where('sequence_scope_key', 'ORG_TYPE_PREFIX=DEP')
        ->firstOrFail();

    expect($burSeq->next_number)->toBe(3)  // 2 generated, next is 3
        ->and($depSeq->next_number)->toBe(2);  // 1 generated, next is 2
});

// ─── 2. Global strategy uses a single counter ─────────────────────────────────

it('global strategy uses one shared counter regardless of context', function (): void {
    OrganizationType::query()->create(['code' => 'ALPHA', 'prefix' => 'ALPHA', 'name_en' => 'Alpha', 'is_active' => true]);
    OrganizationType::query()->create(['code' => 'BETA', 'prefix' => 'BETA', 'name_en' => 'Beta', 'is_active' => true]);

    $alphaType = OrganizationType::query()->where('code', 'ALPHA')->firstOrFail();
    $betaType = OrganizationType::query()->where('code', 'BETA')->firstOrFail();

    $rule = makeScopeRule([
        'format' => '{ORG_TYPE_PREFIX}-{SEQUENCE_PADDED}',
        'prefix' => null,
        'sequence_scope_strategy' => CodeRuleScopeStrategy::Global,
        'active_scope_key' => CodeRule::buildActiveScopeKey(CodeRuleEntityType::Organization),
    ]);

    $gen = app(CodeGeneratorService::class);
    $gen->generate($rule, ['organization_type_id' => $alphaType->id]);
    $gen->generate($rule, ['organization_type_id' => $betaType->id]);

    $sequences = CodeRuleSequence::query()->where('code_rule_id', $rule->id)->get();

    expect($sequences)->toHaveCount(1)
        ->and($sequences->first()->sequence_scope_key)->toBe('_global_')
        ->and($sequences->first()->next_number)->toBe(3);
});

// ─── 3. Auto strategy detects scope tokens ────────────────────────────────────

it('auto strategy detects ORG_TYPE_PREFIX as a scope token', function (): void {
    $resolver = app(SequenceScopeResolver::class);

    $tokens = $resolver->detectAutoScopeTokens('{ORG_TYPE_PREFIX}-{YEAR}-{SEQUENCE_PADDED}');

    expect($tokens)->toContain('ORG_TYPE_PREFIX');
});

it('auto strategy returns global scope when no grouping token is in the format', function (): void {
    $resolver = app(SequenceScopeResolver::class);

    // Only EXCLUDED_TOKENS in the format — nothing should be detected as a scope token
    $tokens = $resolver->detectAutoScopeTokens('{PREFIX}-{SEQUENCE_PADDED}');

    expect($tokens)->toBeEmpty();
});

// ─── 4. Custom tokens strategy ────────────────────────────────────────────────

it('custom_tokens strategy scopes by the admin-specified tokens', function (): void {
    OrganizationType::query()->create(['code' => 'X1', 'prefix' => 'X1', 'name_en' => 'X1', 'is_active' => true]);
    OrganizationType::query()->create(['code' => 'X2', 'prefix' => 'X2', 'name_en' => 'X2', 'is_active' => true]);

    $x1 = OrganizationType::query()->where('code', 'X1')->firstOrFail();
    $x2 = OrganizationType::query()->where('code', 'X2')->firstOrFail();

    $rule = makeScopeRule([
        'format' => '{ORG_TYPE_PREFIX}-{SEQUENCE_PADDED}',
        'prefix' => null,
        'sequence_scope_strategy' => CodeRuleScopeStrategy::CustomTokens,
        'sequence_scope_tokens' => ['ORG_TYPE_PREFIX'],
        'active_scope_key' => CodeRule::buildActiveScopeKey(CodeRuleEntityType::Organization),
    ]);

    $gen = app(CodeGeneratorService::class);
    $gen->generate($rule, ['organization_type_id' => $x1->id]);
    $gen->generate($rule, ['organization_type_id' => $x1->id]);
    $gen->generate($rule, ['organization_type_id' => $x2->id]);

    $x1Seq = CodeRuleSequence::query()
        ->where('code_rule_id', $rule->id)
        ->where('sequence_scope_key', 'ORG_TYPE_PREFIX=X1')
        ->firstOrFail();

    $x2Seq = CodeRuleSequence::query()
        ->where('code_rule_id', $rule->id)
        ->where('sequence_scope_key', 'ORG_TYPE_PREFIX=X2')
        ->firstOrFail();

    expect($x1Seq->next_number)->toBe(3)
        ->and($x2Seq->next_number)->toBe(2);
});

// ─── 5. Preview is read-only ──────────────────────────────────────────────────

it('preview does not create sequence rows', function (): void {
    $rule = makeScopeRule([
        'format' => '{PREFIX}-{YEAR}-{SEQUENCE_PADDED}',
        'prefix' => 'TST',
        'sequence_scope_strategy' => CodeRuleScopeStrategy::Global,
    ]);

    $preview = app(CodeGeneratorService::class)->preview($rule);

    expect($preview)->toStartWith('TST-2026-')
        ->and(CodeRuleSequence::query()->where('code_rule_id', $rule->id)->count())->toBe(0);
});

it('preview does not advance the sequence counter', function (): void {
    OrganizationType::query()->create(['code' => 'PV', 'prefix' => 'PV', 'name_en' => 'Preview', 'is_active' => true]);
    $type = OrganizationType::query()->where('code', 'PV')->firstOrFail();

    $rule = makeScopeRule([
        'format' => '{ORG_TYPE_PREFIX}-{SEQUENCE_PADDED}',
        'prefix' => null,
        'sequence_scope_strategy' => CodeRuleScopeStrategy::Auto,
        'active_scope_key' => CodeRule::buildActiveScopeKey(CodeRuleEntityType::Organization),
    ]);

    $gen = app(CodeGeneratorService::class);

    // Generate once to create a sequence row
    $gen->generate($rule, ['organization_type_id' => $type->id]);

    $before = CodeRuleSequence::query()
        ->where('code_rule_id', $rule->id)
        ->where('sequence_scope_key', 'ORG_TYPE_PREFIX=PV')
        ->value('next_number');

    // Preview multiple times
    $gen->preview($rule, ['organization_type_id' => $type->id]);
    $gen->preview($rule, ['organization_type_id' => $type->id]);

    $after = CodeRuleSequence::query()
        ->where('code_rule_id', $rule->id)
        ->where('sequence_scope_key', 'ORG_TYPE_PREFIX=PV')
        ->value('next_number');

    expect($after)->toBe($before);
});

// ─── 6. Missing context exception ────────────────────────────────────────────

it('throws MissingSequenceScopeContextException when required token value is absent', function (): void {
    $rule = makeScopeRule([
        'format' => '{ORG_TYPE_PREFIX}-{SEQUENCE_PADDED}',
        'prefix' => null,
        'sequence_scope_strategy' => CodeRuleScopeStrategy::CustomTokens,
        'sequence_scope_tokens' => ['ORG_TYPE_PREFIX'],
    ]);

    // Pass empty context — ORG_TYPE_PREFIX cannot be resolved
    expect(fn () => app(CodeGeneratorService::class)->generate($rule, []))
        ->toThrow(MissingSequenceScopeContextException::class);
});

// ─── 7. Validation: custom_tokens must exist in format ───────────────────────

it('rejects custom scope tokens that are not present in the format', function (): void {
    $user = scopeSuperAdmin();

    $this->actingAs($user)
        ->post(route('code-rules.store'), [
            'entity_type' => CodeRuleEntityType::Organization->value,
            'name_en' => 'Scope Token Validation',
            'format' => '{PREFIX}-{SEQUENCE_PADDED}',
            'prefix' => 'ORG',
            'separator' => '-',
            'sequence_length' => 4,
            'next_number' => 1,
            'reset_frequency' => CodeRuleResetFrequency::Never->value,
            'year_format' => 'Y',
            'is_active' => true,
            'allow_manual_override' => false,
            'require_approval_for_override' => true,
            'sequence_scope_strategy' => CodeRuleScopeStrategy::CustomTokens->value,
            'sequence_scope_tokens' => ['ORG_TYPE_PREFIX'],  // not in format
        ])
        ->assertSessionHasErrors('sequence_scope_tokens');
});

it('rejects SEQUENCE as a custom scope token', function (): void {
    $user = scopeSuperAdmin();

    $this->actingAs($user)
        ->post(route('code-rules.store'), [
            'entity_type' => CodeRuleEntityType::Organization->value,
            'name_en' => 'Scope Token Validation',
            'format' => '{PREFIX}-{SEQUENCE_PADDED}',
            'prefix' => 'ORG',
            'separator' => '-',
            'sequence_length' => 4,
            'next_number' => 1,
            'reset_frequency' => CodeRuleResetFrequency::Never->value,
            'year_format' => 'Y',
            'is_active' => true,
            'allow_manual_override' => false,
            'require_approval_for_override' => true,
            'sequence_scope_strategy' => CodeRuleScopeStrategy::CustomTokens->value,
            'sequence_scope_tokens' => ['SEQUENCE'],
        ])
        ->assertSessionHasErrors('sequence_scope_tokens');
});

// ─── 8. Strategy is persisted on store/update ─────────────────────────────────

it('stores the sequence_scope_strategy when creating a code rule', function (): void {
    $user = scopeSuperAdmin();

    $this->actingAs($user)
        ->post(route('code-rules.store'), [
            'entity_type' => CodeRuleEntityType::Employee->value,
            'name_en' => 'Employee Scoped Rule',
            'format' => '{PREFIX}-{YEAR}-{SEQUENCE_PADDED}',
            'prefix' => 'EMP',
            'separator' => '-',
            'sequence_length' => 6,
            'next_number' => 1,
            'reset_frequency' => CodeRuleResetFrequency::Never->value,
            'year_format' => 'Y',
            'is_active' => true,
            'allow_manual_override' => false,
            'require_approval_for_override' => true,
            'sequence_scope_strategy' => CodeRuleScopeStrategy::Global->value,
            'sequence_scope_tokens' => [],
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('code_rules', [
        'entity_type' => CodeRuleEntityType::Employee->value,
        'sequence_scope_strategy' => CodeRuleScopeStrategy::Global->value,
    ]);
});

// ─── 9. Sequence hash uniqueness ──────────────────────────────────────────────

it('stores a sha256 scope hash on the sequence row', function (): void {
    $rule = makeScopeRule([
        'format' => '{PREFIX}-{SEQUENCE_PADDED}',
        'prefix' => 'HX',
        'sequence_scope_strategy' => CodeRuleScopeStrategy::Global,
    ]);

    app(CodeGeneratorService::class)->generate($rule);

    $seq = CodeRuleSequence::query()->where('code_rule_id', $rule->id)->firstOrFail();

    expect($seq->sequence_scope_hash)->toBe(hash('sha256', '_global_'));
});

// ─── 10. last_generated_code is updated ───────────────────────────────────────

it('records the last generated code on the sequence row', function (): void {
    $rule = makeScopeRule([
        'format' => '{PREFIX}-{SEQUENCE_PADDED}',
        'prefix' => 'LC',
        'sequence_scope_strategy' => CodeRuleScopeStrategy::Global,
    ]);

    $gen = app(CodeGeneratorService::class);
    $gen->generate($rule);
    $code = $gen->generate($rule);

    $seq = CodeRuleSequence::query()->where('code_rule_id', $rule->id)->firstOrFail();

    expect($seq->last_generated_code)->toBe($code)
        ->and($seq->last_number)->toBe(2);
});

// ─── 11. Reset sequence endpoint ──────────────────────────────────────────────

it('super admin can reset a sequence counter via the HTTP endpoint', function (): void {
    $user = scopeSuperAdmin();

    $rule = makeScopeRule([
        'format' => '{PREFIX}-{SEQUENCE_PADDED}',
        'prefix' => 'RS',
        'sequence_scope_strategy' => CodeRuleScopeStrategy::Global,
    ]);

    // Generate a few codes so next_number advances
    $gen = app(CodeGeneratorService::class);
    $gen->generate($rule);
    $gen->generate($rule);
    $gen->generate($rule);

    $seq = CodeRuleSequence::query()->where('code_rule_id', $rule->id)->firstOrFail();
    expect($seq->next_number)->toBe(4);

    $this->actingAs($user)
        ->postJson(route('code-rules.sequences.reset', ['codeRule' => $rule->id, 'sequence' => $seq->id]))
        ->assertOk();

    expect($seq->fresh()->next_number)->toBe(1);
});

it('manual reset returns a sequence counter to its configured initial number', function (): void {
    $user = scopeSuperAdmin();
    $rule = makeScopeRule([
        'format' => '{PREFIX}-{SEQUENCE_PADDED}',
        'prefix' => 'RS',
        'next_number' => 25,
        'initial_sequence_number' => 25,
        'sequence_scope_strategy' => CodeRuleScopeStrategy::Global,
    ]);

    app(CodeGeneratorService::class)->generate($rule);
    $sequence = CodeRuleSequence::query()->where('code_rule_id', $rule->id)->firstOrFail();

    $this->actingAs($user)
        ->postJson(route('code-rules.sequences.reset', ['codeRule' => $rule->id, 'sequence' => $sequence->id]))
        ->assertOk();

    expect($sequence->fresh()->next_number)->toBe(25);
});

// ─── 12. Reset endpoint rejects wrong codeRule ownership ──────────────────────

it('returns 404 when sequence does not belong to the given code rule', function (): void {
    $user = scopeSuperAdmin();

    $rule1 = makeScopeRule(['name_en' => 'Rule 1', 'prefix' => 'R1', 'format' => '{PREFIX}-{SEQUENCE_PADDED}', 'sequence_scope_strategy' => CodeRuleScopeStrategy::Global]);
    $rule2 = makeScopeRule(['name_en' => 'Rule 2', 'prefix' => 'R2', 'format' => '{PREFIX}-{SEQUENCE_PADDED}', 'sequence_scope_strategy' => CodeRuleScopeStrategy::Global, 'active_scope_key' => null, 'is_active' => false]);

    // Generate to create a sequence for rule2
    app(CodeGeneratorService::class)->generate($rule2);
    $seq = CodeRuleSequence::query()->where('code_rule_id', $rule2->id)->firstOrFail();

    $this->actingAs($user)
        ->post(route('code-rules.sequences.reset', ['codeRule' => $rule1->id, 'sequence' => $seq->id]))
        ->assertNotFound();
});

// ─── 13. Show page exposes sequences for authorised user ──────────────────────

it('show page includes sequences prop when user has viewSequences permission', function (): void {
    $user = scopeSuperAdmin();

    $rule = makeScopeRule([
        'format' => '{PREFIX}-{SEQUENCE_PADDED}',
        'prefix' => 'SH',
        'sequence_scope_strategy' => CodeRuleScopeStrategy::Global,
    ]);

    app(CodeGeneratorService::class)->generate($rule);

    $this->actingAs($user)
        ->get(route('code-rules.show', $rule))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('CodeRules/Show')
            ->has('sequences')
            ->has('sequences.0.scope_key'));
});

// ─── 14. Backfill command dry-run ─────────────────────────────────────────────

it('backfill command with --dry-run prints output without creating rows', function (): void {
    $rule = makeScopeRule([
        'format' => '{PREFIX}-{SEQUENCE_PADDED}',
        'prefix' => 'BF',
        'sequence_scope_strategy' => CodeRuleScopeStrategy::Global,
    ]);

    // Insert a fake generation log directly (bypassing the generator)
    DB::table('code_generation_logs')->insert([
        'id' => (string) Str::uuid(),
        'code_rule_id' => $rule->id,
        'entity_type' => CodeRuleEntityType::Organization->value,
        'generated_code' => 'BF-0001',
        'sequence_number' => 1,
        'generated_at' => now(),
        'metadata' => json_encode(['sequence_scope_key' => '_global_']),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->artisan('code-rules:backfill-sequences', ['--dry-run' => true])
        ->assertSuccessful();

    // No rows should have been written
    expect(CodeRuleSequence::query()->where('code_rule_id', $rule->id)->count())->toBe(0);
});

// ─── 15. Backfill command creates sequence rows ───────────────────────────────

it('backfill command creates sequence rows from generation logs', function (): void {
    $rule = makeScopeRule([
        'format' => '{PREFIX}-{SEQUENCE_PADDED}',
        'prefix' => 'BL',
        'sequence_scope_strategy' => CodeRuleScopeStrategy::Global,
    ]);

    foreach ([1, 2, 3] as $seq) {
        DB::table('code_generation_logs')->insert([
            'id' => (string) Str::uuid(),
            'code_rule_id' => $rule->id,
            'entity_type' => CodeRuleEntityType::Organization->value,
            'generated_code' => "BL-{$seq}",
            'sequence_number' => $seq,
            'generated_at' => now(),
            'metadata' => json_encode(['sequence_scope_key' => '_global_']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $this->artisan('code-rules:backfill-sequences')
        ->assertSuccessful();

    $seq = CodeRuleSequence::query()->where('code_rule_id', $rule->id)->firstOrFail();

    expect($seq->sequence_scope_key)->toBe('_global_')
        ->and($seq->next_number)->toBe(4)     // max(3) + 1
        ->and($seq->last_number)->toBe(3)
        ->and($seq->last_generated_code)->toBe('BL-3');
});

// ─── 16. New scope always starts from initial_sequence_number ─────────────────

it('new per-scope sequence starts at initial_sequence_number even after global scope advanced next_number', function (): void {
    // Simulate the real-world bug: a rule that first generated codes under global
    // scope (advancing next_number to 3), then encounters a new prefix for the
    // first time. Without the fix, the new scope would start at 3 instead of 1.

    OrganizationType::query()->create(['code' => 'AA', 'prefix' => 'AA', 'name_en' => 'Alpha', 'is_active' => true]);
    OrganizationType::query()->create(['code' => 'BB', 'prefix' => 'BB', 'name_en' => 'Beta', 'is_active' => true]);

    $aa = OrganizationType::query()->where('code', 'AA')->firstOrFail();
    $bb = OrganizationType::query()->where('code', 'BB')->firstOrFail();

    // Rule starts with next_number = initial_sequence_number = 1
    $rule = makeScopeRule([
        'format' => '{ORG_TYPE_PREFIX}-{SEQUENCE_PADDED}',
        'prefix' => null,
        'next_number' => 1,
        'sequence_scope_strategy' => CodeRuleScopeStrategy::Auto,
        'active_scope_key' => CodeRule::buildActiveScopeKey(CodeRuleEntityType::Organization),
    ]);

    // Forcibly advance next_number as if global-scope codes had been generated
    // (this is what happens when a rule previously ran under global scope).
    $rule->forceFill(['next_number' => 5])->save();

    $gen = app(CodeGeneratorService::class);

    // Generate two codes for AA — creates scope UNIT_TYPE_PREFIX=AA starting at initial_sequence_number=1
    $aa1 = $gen->generate($rule, ['organization_type_id' => $aa->id]);
    $aa2 = $gen->generate($rule, ['organization_type_id' => $aa->id]);

    // Generate first code for BB — should start at 1, not at next_number (5)
    $bb1 = $gen->generate($rule, ['organization_type_id' => $bb->id]);

    expect($aa1)->toBe('AA-0001')
        ->and($aa2)->toBe('AA-0002')
        ->and($bb1)->toBe('BB-0001');
});

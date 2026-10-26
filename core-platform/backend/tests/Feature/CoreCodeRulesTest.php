<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CodeRule;
use App\Models\Organization;
use App\Models\User;
use App\Services\CodeGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsGoldenPath;
use Tests\TestCase;

/**
 * The EUISIS Code Rules screens on the Core: the pages render, a rule is
 * written, previewed, archived and restored, and the Core's generator numbers
 * new records from the active rule, or from the settings formats without one.
 */
class CoreCodeRulesTest extends TestCase
{
    use BuildsGoldenPath;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildGoldenPath();
    }

    private function signIn(string $role = 'core_admin'): User
    {
        $user = User::query()->create([
            'name' => $role, 'email' => $role.'-'.Str::random(6).'@core.test', 'password' => 'secret-password-1',
            'role' => $role, 'scope_type' => User::SCOPE_CITYWIDE, 'is_active' => true,
        ]);
        $this->actingAs($user, 'web');

        return $user;
    }

    /** @return array<string, mixed> */
    private function rule(array $overrides = []): array
    {
        return $overrides + [
            'entity_type' => 'organization', 'scope_type' => null, 'scope_id' => null,
            'name_en' => 'Organization codes', 'name_am' => null, 'prefix' => 'ORG', 'suffix' => null, 'separator' => '-',
            'format' => '{PREFIX}-{YEAR}-{SEQUENCE_PADDED}', 'sequence_length' => 4, 'next_number' => 1, 'reset_frequency' => 'never', 'year_format' => 'Y',
            'is_active' => true, 'allow_manual_override' => true, 'require_approval_for_override' => false,
            'description_en' => null, 'description_am' => null, 'metadata' => null, 'sequence_scope_strategy' => 'auto', 'sequence_scope_tokens' => [],
        ];
    }

    public function test_the_pages_render_the_euisis_components_and_a_rule_is_written_previewed_and_archived(): void
    {
        $this->signIn();

        $this->get('/code-rules')->assertInertia(fn (Assert $page) => $page->component('CodeRules/Index')
            ->where('summary.total', 0)->where('can.create', true)->has('options.entity_types', 7)->has('options.scope_types', 3));
        $this->get('/code-rules/create')->assertInertia(fn (Assert $page) => $page->component('CodeRules/Create')->has('available_tokens'));

        $this->postJson('/code-rules/preview', $this->rule())->assertOk()->assertJson(['preview' => 'ORG-'.now()->format('Y').'-0001']);

        $this->post('/code-rules', $this->rule())->assertSessionHasNoErrors()->assertRedirect();
        $codeRule = CodeRule::query()->firstOrFail();
        $this->assertSame('organization', $codeRule->entity_type->value);
        $this->assertSame('organization|global|global', $codeRule->active_scope_key);
        $this->assertDatabaseHas('core_audit_logs', ['action' => 'code_rule.created', 'subject_id' => $codeRule->id]);

        // A second active global rule for the same entity is refused.
        $this->post('/code-rules', $this->rule(['name_en' => 'Another']))->assertSessionHasErrors('entity_type');

        $this->get('/code-rules/'.$codeRule->id)->assertInertia(fn (Assert $page) => $page->component('CodeRules/Show')
            ->where('codeRule.preview', 'ORG-'.now()->format('Y').'-0001')->where('can.viewSequences', true));
        $this->get('/code-rules/'.$codeRule->id.'/edit')->assertInertia(fn (Assert $page) => $page->component('CodeRules/Edit'));
        $this->put('/code-rules/'.$codeRule->id, $this->rule(['prefix' => 'ORGX']))->assertSessionHasNoErrors();
        $this->assertSame('ORGX', $codeRule->fresh()->prefix);

        $this->post('/code-rules/'.$codeRule->id.'/archive', ['reason' => 'Replaced'])->assertRedirect('/code-rules');
        $this->assertSoftDeleted('code_rules', ['id' => $codeRule->id]);
        $this->assertFalse($codeRule->fresh()->is_active);
        $this->post('/code-rules/'.$codeRule->id.'/restore')->assertSessionHasNoErrors();
        $this->assertTrue($codeRule->fresh()->is_active);
    }

    public function test_the_core_numbers_from_the_active_rule_and_from_the_settings_without_one(): void
    {
        $this->signIn();
        $generator = app(CodeGenerator::class);

        $year = now()->format('Y');

        // No rule: the format under System Settings > Numbering (no year segment in it).
        $this->assertNotNull($generator->preview('organization'));
        $this->assertStringNotContainsString("-{$year}-", (string) $generator->preview('organization'));

        $this->post('/code-rules', $this->rule())->assertSessionHasNoErrors();
        $rule = CodeRule::query()->firstOrFail();

        $this->assertSame("ORG-{$year}-0001", $generator->preview('organization'));
        $this->assertSame("ORG-{$year}-0001", $generator->code('organization'));
        $this->assertSame("ORG-{$year}-0002", $generator->code('organization'));
        $this->assertSame('MANUAL-1', $generator->code('organization', 'MANUAL-1'));
        $this->assertSame(2, $rule->generationLogs()->count());
        $this->assertSame(3, (int) $rule->sequences()->value('next_number'));

        // A code already taken by imported data is skipped.
        Organization::query()->whereKey($this->bureauA->id)->update(['code' => "ORG-{$year}-0003"]);
        $this->assertSame("ORG-{$year}-0004", $generator->code('organization'));

        // The create forms' preview answers with the rule; employees have no rule, so the settings format answers.
        $this->postJson('/code-rules/preview-code', ['entity_type' => 'organization'])->assertOk()->assertJsonPath('code', "ORG-{$year}-0005")->assertJsonPath('rule.id', $rule->id);
        $this->postJson('/code-rules/preview-code', ['entity_type' => 'employee'])->assertOk()->assertJsonPath('rule.id', 'employee')->assertJsonMissingPath('code.rule');
        $this->assertNotNull($this->postJson('/code-rules/preview-code', ['entity_type' => 'employee'])->json('code'));

        // The sequence can be reset to the configured start.
        $sequence = $rule->sequences()->firstOrFail();
        $this->post("/code-rules/{$rule->id}/sequences/{$sequence->id}/reset")->assertOk()->assertJsonStructure(['message']);
        $this->assertSame(1, (int) $sequence->fresh()->next_number);
    }

    public function test_only_holders_of_the_permissions_reach_the_screen(): void
    {
        $this->signIn('core_viewer');
        $this->get('/code-rules')->assertForbidden();

        $this->signIn('core_officer');
        $this->get('/code-rules')->assertOk();
        $this->get('/code-rules/create')->assertForbidden();
        $this->post('/code-rules', $this->rule())->assertForbidden();
        $this->assertSame(0, DB::table('code_rules')->count());
    }
}

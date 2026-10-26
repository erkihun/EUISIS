<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\RegisterEmployee;
use App\Models\ApiClient;
use App\Models\IdCard;
use App\Models\Organization;
use App\Models\User;
use App\Services\OrganizationScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsGoldenPath;
use Tests\TestCase;

/** P0 (ADR 0008): organization scope is explicit and fails closed, on the API, the screens and the actions. */
class CoreScopeFailClosedTest extends TestCase
{
    use BuildsGoldenPath, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildGoldenPath();
    }

    private function account(string $role, ?string $type, ?Organization $organization = null): User
    {
        return User::query()->create([
            'name' => 'Account '.Str::random(4), 'email' => Str::random(8).'@core.test', 'password' => 'secret-password-1',
            'role' => $role, 'scope_type' => $type, 'scope_organization_id' => $organization?->id, 'is_active' => true,
        ]);
    }

    public function test_an_account_without_a_scope_reaches_nothing_through_the_api_even_as_administrator(): void
    {
        Sanctum::actingAs($this->account('core_admin', null));
        $deny = fn ($response) => $response->assertForbidden()->assertJsonPath('code', 'NO_ORGANIZATION_SCOPE');

        $deny($this->getJson('/api/v1/employees'));
        $deny($this->getJson('/api/v1/employees?search=Abebe'));
        $deny($this->getJson('/api/v1/employees/'.$this->employee->id));
        $deny($this->putJson('/api/v1/employees/'.$this->employee->id, ['first_name' => 'Changed']));
        $deny($this->getJson('/api/v1/organizations/'.$this->bureauA->id));
        $deny($this->getJson('/api/v1/lookups'));
        $deny($this->postJson('/api/v1/positions', ['organization_id' => $this->bureauA->id, 'title_en' => 'Clerk']));
        $deny($this->postJson('/api/v1/employees/'.$this->employee->id.'/transfer', ['position_id' => $this->positionB->id, 'effective_date' => '2025-03-01']));
        $deny($this->getJson('/api/v1/id-cards'));
        $deny($this->postJson('/api/v1/cards/verify', ['card_uuid' => $this->card->public_card_uuid]));
        $deny($this->getJson('/api/v1/hierarchy'));

        $this->assertSame($this->employee->fresh()->first_name === 'Changed', false);
        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('user.scope_type', null);
    }

    public function test_the_scope_service_and_the_actions_fail_closed_without_a_scope(): void
    {
        $unscoped = $this->account('core_admin', null);
        $scope = app(OrganizationScope::class);

        $this->assertSame([], $scope->organizationIds($unscoped));
        $this->assertFalse($scope->allows($unscoped, $this->bureauA->id));
        $this->assertFalse($scope->allows($unscoped, null));
        // A scope type without its organization reaches nothing either.
        $this->assertSame([], $scope->organizationIds($this->account('core_admin', User::SCOPE_SUBTREE)));

        $this->expectException(ValidationException::class);
        app(RegisterEmployee::class)->execute(['first_name' => 'No', 'last_name' => 'Scope', 'position_id' => $this->positionA->id, 'effective_from' => '2025-01-01'], $unscoped);
    }

    public function test_an_account_without_a_scope_sees_no_screen_but_its_dashboard_and_can_still_sign_out(): void
    {
        $this->actingAs($this->account('core_admin', null), 'web');

        foreach (['/employees', '/employees/'.$this->employee->id, '/organizations/'.$this->bureauA->id, '/organizations/'.$this->bureauA->id.'/edit',
            '/organizations/parent-options?q=BUR', '/positions/'.$this->positionA->id.'/edit', '/id-cards', '/core/assignments'] as $path) {
            $this->get($path)->assertForbidden();
        }
        $this->get('/core')->assertOk()->assertInertia(fn ($page) => $page->where('flash.warning', 'This user has no organization scope.'));
        $this->post('/logout')->assertRedirect();
    }

    public function test_an_explicit_city_wide_account_reaches_the_whole_city(): void
    {
        $other = $this->placedEmployee('EMP-B', $this->positionB, '2024-01-01');
        Sanctum::actingAs($this->account('core_viewer', User::SCOPE_CITYWIDE));

        $numbers = array_column($this->getJson('/api/v1/employees')->assertOk()->json('data'), 'employee_number');
        $this->assertEqualsCanonicalizing(['EMP-0001', 'EMP-B'], $numbers);
        $this->getJson('/api/v1/employees/'.$other->id)->assertOk();
    }

    public function test_a_subtree_account_reaches_its_organization_and_everything_under_it_only(): void
    {
        $other = $this->placedEmployee('EMP-B', $this->positionB, '2024-01-01');
        $otherCard = IdCard::query()->create(['employee_id' => $other->id, 'card_number' => 'CARD-B', 'status' => 'active', 'is_current' => true, 'public_card_uuid' => (string) Str::uuid()]);
        Sanctum::actingAs($this->account('core_officer', User::SCOPE_SUBTREE, $this->bureauA));

        $this->assertSame(['EMP-0001'], array_column($this->getJson('/api/v1/employees?search=EMP')->json('data'), 'employee_number'));
        // Guessing another organization's UUIDs finds nothing.
        $this->getJson('/api/v1/employees/'.$other->id)->assertNotFound();
        $this->putJson('/api/v1/employees/'.$other->id, ['first_name' => 'X'])->assertNotFound();
        $this->getJson('/api/v1/organizations/'.$this->bureauB->id)->assertNotFound();
        $this->postJson('/api/v1/id-cards/'.$otherCard->id.'/suspend', ['reason' => 'Not mine to touch'])->assertNotFound();
        $this->postJson('/api/v1/cards/verify', ['card_uuid' => $otherCard->public_card_uuid])->assertNotFound();
        // Autocomplete lists only the organizations in scope.
        $this->assertSame([$this->bureauA->id], array_column($this->getJson('/api/v1/lookups')->json('organizations'), 'id'));

        Auth::forgetGuards();
        $this->actingAs($this->account('core_officer', User::SCOPE_SUBTREE, $this->bureauA), 'web');
        $this->get('/employees/'.$other->id)->assertNotFound();
        $this->get('/organizations/'.$this->bureauB->id.'/edit')->assertNotFound();
        $this->get('/positions/'.$this->positionB->id.'/edit')->assertNotFound();
        $options = collect($this->getJson('/organizations/parent-options?q=BUR')->assertOk()->json())->flatten()->implode(' ');
        $this->assertStringNotContainsString($this->bureauB->id, $options);
    }

    public function test_a_self_scope_reaches_the_organization_alone(): void
    {
        Sanctum::actingAs($this->account('core_viewer', User::SCOPE_SELF, $this->city));

        $this->assertSame([$this->city->id], array_column($this->getJson('/api/v1/organizations')->assertOk()->json('data'), 'id'));
        $this->getJson('/api/v1/organizations/'.$this->bureauA->id)->assertNotFound();
    }

    public function test_only_a_city_wide_administrator_grants_city_wide_access_on_the_screens(): void
    {
        $this->actingAs($this->account('core_admin', User::SCOPE_SUBTREE, $this->bureauA), 'web');
        $page = $this->get('/users/create')->assertOk();
        $this->assertNotContains('global', array_column($page->viewData('page')['props']['roles'], 'scope'));
        $this->post('/users', ['name' => 'Wide', 'email' => 'wide@core.test', 'status' => 'active', 'role_ids' => [32]])->assertForbidden();

        Auth::forgetGuards();
        $this->actingAs($this->account('core_admin', User::SCOPE_CITYWIDE), 'web');
        $this->post('/users', ['name' => 'Nowhere', 'email' => 'nowhere@core.test', 'status' => 'active', 'role_ids' => [21]])
            ->assertSessionHasErrors('organization_scope_ids');
        $this->post('/users', ['name' => 'Wide', 'email' => 'wide@core.test', 'status' => 'active', 'role_ids' => [32]])->assertRedirect();
        $this->assertSame(User::SCOPE_CITYWIDE, User::query()->where('email', 'wide@core.test')->value('scope_type'));
    }

    public function test_an_api_client_without_an_explicit_scope_is_refused(): void
    {
        $client = new ApiClient(['name' => 'Legacy', 'scopes' => ['organizations:read'], 'is_active' => true]);
        $client->save();
        $secret = $client->createToken('integration', ['organizations:read'])->plainTextToken;

        $this->getJson('/api/v1/integration/organizations', ['Authorization' => 'Bearer '.$secret])
            ->assertForbidden()->assertJsonPath('code', 'NO_ORGANIZATION_SCOPE');
    }

    public function test_the_backfill_keeps_existing_grants_explicit(): void
    {
        $migration = require database_path('migrations/2026_10_26_100000_make_organization_scope_explicit.php');
        $migration->down();
        $wide = DB::table('users')->insertGetId(['name' => 'Old wide', 'email' => 'old-wide@core.test', 'password' => 'x', 'role' => 'core_admin', 'is_active' => true]);
        $scoped = DB::table('users')->insertGetId(['name' => 'Old scoped', 'email' => 'old-scoped@core.test', 'password' => 'x', 'role' => 'core_viewer', 'scope_organization_id' => $this->bureauA->id, 'is_active' => true]);
        $migration->up();

        $this->assertTrue(Schema::hasColumn('users', 'scope_type'));
        $this->assertSame(User::SCOPE_CITYWIDE, DB::table('users')->where('id', $wide)->value('scope_type'));
        $this->assertSame(User::SCOPE_SUBTREE, DB::table('users')->where('id', $scoped)->value('scope_type'));
    }
}

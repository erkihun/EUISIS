<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsGoldenPath;
use Tests\TestCase;

class CoreAdminScopeTest extends TestCase
{
    use BuildsGoldenPath, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildGoldenPath();
    }

    private function payload(?string $scope): array
    {
        return ['name' => 'Managed account', 'email' => 'managed@core.test', 'role' => 'core_officer', 'scope_organization_id' => $scope];
    }

    public function test_scoped_admin_cannot_grant_citywide_or_other_bureau_access(): void
    {
        $actor = $this->actingAsCore('core_admin', $this->bureauA);
        $this->postJson('/api/v1/users', [...$this->payload(null), 'scope_type' => User::SCOPE_CITYWIDE])->assertForbidden();
        $this->postJson('/api/v1/users', $this->payload($this->bureauB->id))->assertForbidden();
        $this->putJson('/api/v1/users/'.$actor->id, [
            ...$this->payload(null), 'scope_type' => User::SCOPE_CITYWIDE, 'email' => $actor->email, 'role' => 'core_admin',
        ])->assertForbidden();
        // Leaving the scope out is not the whole city any more: the account reaches nothing (ADR 0008).
        $this->postJson('/api/v1/users', $this->payload(null))->assertCreated()->assertJsonPath('data.scope_type', null);
        $this->assertSame($this->bureauA->id, $actor->fresh()->scope_organization_id);
    }

    public function test_scoped_admin_cannot_read_edit_or_reset_outside_accounts(): void
    {
        $outside = User::query()->create([
            'name' => 'Outside account', 'email' => 'outside@core.test', 'password' => 'original-password',
            'role' => 'core_admin', 'scope_organization_id' => $this->bureauB->id, 'scope_type' => User::SCOPE_SUBTREE, 'is_active' => true,
        ]);
        $password = $outside->password;
        $this->actingAsCore('core_admin', $this->bureauA);
        $this->getJson('/api/v1/users')->assertOk()->assertJsonMissing(['email' => $outside->email]);
        $this->putJson('/api/v1/users/'.$outside->id, $this->payload($this->bureauA->id))->assertForbidden();
        $this->postJson('/api/v1/users/'.$outside->id.'/reset-password')->assertForbidden();
        $this->assertSame($password, $outside->fresh()->password);
    }

    public function test_scoped_admin_can_manage_an_account_inside_the_scope(): void
    {
        $this->actingAsCore('core_admin', $this->bureauA);
        $id = $this->postJson('/api/v1/users', $this->payload($this->bureauA->id))
            ->assertCreated()->json('data.id');
        $this->postJson('/api/v1/users/'.$id.'/reset-password')->assertOk();
    }

    public function test_global_administration_requires_citywide_scope(): void
    {
        $this->actingAsCore('core_admin', $this->bureauA);
        $this->getJson('/api/v1/settings')->assertForbidden();
        $this->putJson('/api/v1/settings/security', [])->assertForbidden();
        $this->getJson('/api/v1/data-quality')->assertForbidden();
        $this->actingAsCore('core_admin');
        $this->getJson('/api/v1/settings')->assertOk();
        $this->getJson('/api/v1/data-quality')->assertOk();
    }

    public function test_access_changes_revoke_existing_tokens(): void
    {
        $this->actingAsCore('core_admin');
        $id = $this->postJson('/api/v1/users', $this->payload($this->bureauA->id))->assertCreated()->json('data.id');
        $user = User::query()->findOrFail($id);
        $user->createToken('existing-session');
        $this->putJson('/api/v1/users/'.$id, [...$this->payload($this->bureauA->id), 'role' => 'core_viewer'])->assertOk();
        $this->assertSame(0, $user->tokens()->count());
    }
}

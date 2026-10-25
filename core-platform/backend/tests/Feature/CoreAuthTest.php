<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CoreAuthTest extends TestCase
{
    use RefreshDatabase;

    private function account(bool $active = true): User
    {
        return User::query()->create([
            'name' => 'Officer',
            'email' => 'officer@core.test',
            'password' => 'secret-password',
            'role' => 'core_officer',
            'is_active' => $active,
        ]);
    }

    public function test_login_returns_a_token_that_reaches_the_api(): void
    {
        $this->account();

        $token = $this->postJson('/api/auth/login', ['email' => 'officer@core.test', 'password' => 'secret-password'])
            ->assertOk()
            ->assertJsonPath('user.role', 'core_officer')
            ->json('token');

        $this->withToken($token)->getJson('/api/auth/me')->assertOk()->assertJsonPath('user.email', 'officer@core.test');
    }

    public function test_a_wrong_password_is_refused(): void
    {
        $this->account();

        $this->postJson('/api/auth/login', ['email' => 'officer@core.test', 'password' => 'wrong-password'])->assertStatus(422);
    }

    public function test_an_inactive_account_cannot_sign_in(): void
    {
        $this->account(active: false);

        $this->postJson('/api/auth/login', ['email' => 'officer@core.test', 'password' => 'secret-password'])->assertStatus(422);
    }

    public function test_the_api_needs_a_token(): void
    {
        $this->getJson('/api/v1/dashboard')->assertUnauthorized();
    }

    public function test_logout_revokes_the_token(): void
    {
        $this->account();
        $token = $this->postJson('/api/auth/login', ['email' => 'officer@core.test', 'password' => 'secret-password'])->json('token');

        $this->withToken($token)->postJson('/api/auth/logout')->assertOk()->assertJsonPath('signed_out', true);
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson('/api/auth/me')->assertUnauthorized();
    }
}

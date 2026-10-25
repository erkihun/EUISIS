<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Final certification (ADR 0011): no seeded accounts or default passwords, and the screens' security headers. */
class CoreDeploymentSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeding_creates_no_account_and_the_uat_data_refuses_production(): void
    {
        $this->artisan('db:seed', ['--force' => true])->assertSuccessful();
        $this->assertSame(0, User::query()->count());

        $this->app->detectEnvironment(fn (): string => 'production');
        $this->artisan('core:seed-uat')->assertFailed();
        $this->assertSame(0, User::query()->count());
    }

    public function test_pages_carry_the_security_headers(): void
    {
        $response = $this->get('/login')->assertOk();
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        $this->assertStringContainsString("frame-ancestors 'none'", (string) $response->headers->get('Content-Security-Policy-Report-Only'));
    }
}

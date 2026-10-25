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

    public function test_the_inline_theme_script_carries_the_policy_nonce_and_enforcement_is_a_switch(): void
    {
        $response = $this->get('/login')->assertOk();
        preg_match("/'nonce-([A-Za-z0-9]+)'/", (string) $response->headers->get('Content-Security-Policy-Report-Only'), $header);
        $this->assertNotEmpty($header[1] ?? null, 'the policy names a nonce');
        $this->assertStringContainsString('<script nonce="'.$header[1].'">', (string) $response->getContent());
        // Every inline script on the page carries it: no <script> without a nonce or a src (JSON data blocks are not executed).
        preg_match_all('/<script(?![^>]*\b(?:nonce|src)=)(?![^>]*type="application\/json")[^>]*>/', (string) $response->getContent(), $bare);
        $this->assertSame([], $bare[0]);

        $second = $this->get('/login');
        $this->assertStringNotContainsString($header[1], (string) $second->headers->get('Content-Security-Policy-Report-Only'), 'a fresh nonce per request');

        config(['core.csp_enforce' => true]);
        $enforced = $this->get('/login');
        $this->assertNotNull($enforced->headers->get('Content-Security-Policy'));
        $this->assertNull($enforced->headers->get('Content-Security-Policy-Report-Only'));
    }
}

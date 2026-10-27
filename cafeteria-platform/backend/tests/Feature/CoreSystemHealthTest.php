<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\SystemHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\BuildsGoldenPath;
use Tests\TestCase;

/** System health reports measured state, says what it cannot see, and is for city-wide administrators. */
class CoreSystemHealthTest extends TestCase
{
    use BuildsGoldenPath, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildGoldenPath();
    }

    public function test_health_is_measured_and_never_claims_what_it_cannot_see(): void
    {
        $this->actingAsCore('core_admin');

        $response = $this->getJson('/api/v1/system-health')->assertOk()
            ->assertJsonPath('checks.database.status', 'ok')
            ->assertJsonPath('checks.cache.details.round_trip', true)
            ->assertJsonPath('checks.storage.details.writable', true)
            ->assertJsonPath('checks.backup.status', 'unknown')
            ->assertJsonPath('checks.scheduler.status', 'unknown')
            // One city-wide administrator only: a warning, never "ok".
            ->assertJsonPath('checks.access.status', 'warning');
        foreach (['password', 'username', 'host', 'secret', 'key'] as $sensitive) {
            $this->assertStringNotContainsString('"'.$sensitive.'"', (string) $response->getContent());
        }

        foreach (array_keys(SystemHealth::SCHEDULED) as $command) {
            Artisan::call($command);
        }
        $this->getJson('/api/v1/system-health')->assertJsonPath('checks.scheduler.status', 'ok');
    }

    public function test_health_is_for_city_wide_administrators(): void
    {
        $this->actingAsCore('core_admin', $this->bureauA);
        $this->getJson('/api/v1/system-health')->assertForbidden();
        $this->actingAsCore('core_officer');
        $this->getJson('/api/v1/system-health')->assertForbidden();
    }
}

<?php

declare(strict_types=1);

use App\Enums\OrganizationStatus;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Support\PilotWeek\PilotWeekProgress;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

/*
| The temporary pilot week dashboard: an unlisted page that needs no sign-in
| and is reached only through its own link.
*/

beforeEach(function (): void {
    config(['pilot_dashboard.enabled' => true, 'pilot_dashboard.until' => '2099-12-31', 'pilot_dashboard.token' => 'test-token-123', 'pilot_dashboard.cache_seconds' => 0]);
    Cache::flush();
});

function pilotDashboardOrganization(string $code, string $name): Organization
{
    $type = OrganizationType::query()->firstOrCreate(['code' => 'PILOT-T'], ['name_en' => 'Bureau', 'name_am' => 'ቢሮ']);

    return Organization::query()->create([
        'organization_type_id' => $type->id,
        'code' => $code,
        'name_en' => $name,
        'name_am' => $name,
        'status' => OrganizationStatus::Active,
        'effective_from' => now()->toDateString(),
    ]);
}

it('opens without signing in through its own link and shows counts', function (): void {
    $organization = pilotDashboardOrganization('PIL-1', 'የሙከራ ቢሮ');
    OrganizationUnit::query()->create([
        'organization_id' => $organization->id, 'code' => 'PIL-U1', 'name_en' => 'Unit', 'name_am' => 'ክፍል', 'unit_type' => 'team', 'status' => 'active',
    ]);

    $response = $this->get('/week-progress/test-token-123');

    $response->assertOk()
        ->assertSee('የሳምንቱ የሥራ ክንውን')
        ->assertSee('የሙከራ ቢሮ')
        ->assertSee('ከስድስቱ 1 ተከናውኗል')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
    $this->assertGuest();
});

it('answers not found for a wrong token, when switched off and after its last day', function (): void {
    $this->get('/week-progress/wrong-token-999')->assertNotFound();

    config(['pilot_dashboard.enabled' => false]);
    $this->get('/week-progress/test-token-123')->assertNotFound();

    config(['pilot_dashboard.enabled' => true, 'pilot_dashboard.until' => '2000-01-01']);
    $this->get('/week-progress/test-token-123')->assertNotFound();
});

it('derives a stable secret from the application key when no token is set', function (): void {
    config(['pilot_dashboard.token' => null]);

    $token = PilotWeekProgress::token();

    expect($token)->toHaveLength(24)->and(PilotWeekProgress::token())->toBe($token);
    $this->get('/week-progress/'.$token)->assertOk();
});

it('counts a record under the week it was created in', function (): void {
    $organization = pilotDashboardOrganization('PIL-2', 'ሁለተኛ ቢሮ');
    $unit = OrganizationUnit::query()->create([
        'organization_id' => $organization->id, 'code' => 'PIL-U2', 'name_en' => 'Unit', 'name_am' => 'ክፍል', 'unit_type' => 'team', 'status' => 'active',
    ]);
    $unit->forceFill(['created_at' => now()->subWeeks(3)])->saveQuietly();

    $current = app(PilotWeekProgress::class)->forWeek();
    $earlier = app(PilotWeekProgress::class)->forWeek(now()->subWeeks(3)->toDateString());

    expect($current['totals']['units'])->toBe(['total' => 1, 'week' => 0])
        ->and($earlier['totals']['units']['week'])->toBe(1);
});

it('shows only the organizations named in the configuration, and never a demo one', function (): void {
    pilotDashboardOrganization('PIL-3', 'ሦስተኛ ቢሮ');
    pilotDashboardOrganization('PIL-4', 'አራተኛ ቢሮ');
    pilotDashboardOrganization('PIL-5', 'የማሳያ ቢሮ')->forceFill(['is_demo' => true])->saveQuietly();

    $all = array_column(app(PilotWeekProgress::class)->forWeek()['organizations'], 'code');
    expect($all)->toContain('PIL-3', 'PIL-4')->not->toContain('PIL-5');

    config(['pilot_dashboard.organization_codes' => ['PIL-4']]);
    expect(array_column(app(PilotWeekProgress::class)->forWeek()['organizations'], 'code'))->toBe(['PIL-4']);
});

it('is not linked from the application or the public site', function (): void {
    // Unlisted by design: the only way in is the printed link.
    $route = Route::getRoutes()->getByName('pilot.week-dashboard');
    expect($route->gatherMiddleware())->not->toContain('web')->not->toContain('auth');

    $mentions = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('js'), FilesystemIterator::SKIP_DOTS)) as $file) {
        if (in_array($file->getExtension(), ['ts', 'tsx'], true) && $file->getFilename() !== 'ziggy.js'
            && str_contains((string) file_get_contents($file->getPathname()), 'week-progress')) {
            $mentions[] = $file->getPathname();
        }
    }
    expect($mentions)->toBe([]);
});

<?php

declare(strict_types=1);

use App\Http\Middleware\SetClientLocale;
use App\Models\User;
use App\Support\Rbac\PermissionCatalog;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;

/*
 * Court Cases is a planned standalone module (docs/court-cases.md): one
 * protected placeholder page behind court_cases.view, its own sidebar group,
 * and nothing public or inside Grievance Management.
 */

function courtCasesUser(bool $canView): User
{
    $user = User::factory()->create();

    if ($canView) {
        Permission::findOrCreate('court_cases.view', 'web');
        $user->givePermissionTo('court_cases.view');
    }

    return $user;
}

function courtCasesSource(string $path): string
{
    return (string) file_get_contents(__DIR__.'/../../../'.$path);
}

/** @return list<RoutingRoute> */
function courtCasesRoutes(): array
{
    return array_values(array_filter(
        Route::getRoutes()->getRoutes(),
        fn (RoutingRoute $route): bool => str_contains($route->uri(), 'court-case') || str_starts_with((string) $route->getName(), 'court-cases.'),
    ));
}

it('sends guests to the login page', function (): void {
    $this->get('/court-cases')->assertRedirect(route('login'));
});

it('refuses a signed-in user without court_cases.view', function (): void {
    $this->actingAs(courtCasesUser(false))->get(route('court-cases.index'))->assertForbidden();
});

it('shows the placeholder page to a user with court_cases.view', function (): void {
    $this->actingAs(courtCasesUser(true))
        ->get(route('court-cases.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('CourtCases/Index'));
});

it('registers only the entry permission, in its own category', function (): void {
    $names = PermissionCatalog::matching('court_cases');

    expect($names)->toBe(['court_cases.view'])
        ->and(PermissionCatalog::category('court_cases'))->toBe('Court Cases')
        ->and(PermissionCatalog::problems())->toBe([]);
});

it('gives the sidebar the court cases entry only when the user holds the permission', function (): void {
    $sidebar = courtCasesSource('resources/js/Components/AppSidebar.tsx');
    $group = strpos($sidebar, "key: 'courtCases'");
    $grievances = strpos($sidebar, "key: 'grievances'");
    $item = strpos($sidebar, "routeName: 'court-cases.index'");

    // The item is gated by court_cases.view and sits in its own group, not under Grievance Management.
    expect(substr_count($sidebar, "routeName: 'court-cases.index'"))->toBe(1)
        ->and($sidebar)->toContain("{ routeName: 'court-cases.index', labelKey: 'nav.courtCases', icon: ScaleIcon, permission: 'court_cases.view' }")
        ->and($group)->toBeGreaterThan($grievances)
        ->and($item)->toBeGreaterThan($group)
        ->and($item > $grievances && $item < $group)->toBeFalse();

    // The sidebar filters on the permissions shared with every page.
    $this->actingAs(courtCasesUser(false))->get(route('profile.edit'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('auth.permissions', fn ($permissions) => ! collect($permissions)->contains('court_cases.view')));
    $this->actingAs(courtCasesUser(true))->get(route('profile.edit'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('auth.permissions', fn ($permissions) => collect($permissions)->contains('court_cases.view')));
});

it('has the module name, description and status in English and Amharic', function (): void {
    $en = courtCasesSource('resources/js/i18n/en/courtCases.ts');
    $am = courtCasesSource('resources/js/i18n/am/courtCases.ts');

    expect($en)->toContain("title: 'Court Cases'")
        ->toContain("description: 'Management of court-related cases involving employees and institutions will be implemented as a dedicated module in a future phase.'")
        ->toContain("planned: 'Planned Module'")
        ->and($am)->toContain("title: 'የፍርድ ቤት ጉዳዮች'")
        ->toContain("description: 'ከሰራተኞችና ከተቋማት ጋር የተያያዙ የፍርድ ቤት ጉዳዮች አስተዳደር በቀጣይ ደረጃ ራሱን የቻለ ሞጁል ሆኖ ይገነባል።'")
        ->toContain("planned: 'የታቀደ ሞጁል'")
        ->and(courtCasesSource('resources/js/i18n/en/navigation.ts'))->toContain("courtCases: 'Court Cases'")
        ->and(courtCasesSource('resources/js/i18n/am/navigation.ts'))->toContain("courtCases: 'የፍርድ ቤት ጉዳዮች'")
        // The grievance tribunal register keeps a distinct Amharic name.
        ->not->toContain("tribunalCases: 'የፍርድ ቤት ጉዳዮች'");

    // Both languages define the same keys, and the namespace is registered.
    preg_match_all('/^\s{4}(\w+):/m', $en, $enKeys);
    preg_match_all('/^\s{4}(\w+):/m', $am, $amKeys);
    expect($amKeys[1])->toBe($enKeys[1])
        ->and(courtCasesSource('resources/js/hooks/useLocale.ts'))
        ->toContain('courtCases: enCourtCases')
        ->toContain('courtCases: amCourtCases');
});

it('serves the page in the viewer\'s locale', function (string $locale): void {
    $this->actingAs(courtCasesUser(true))
        ->withUnencryptedCookie(SetClientLocale::COOKIE, $locale)
        ->get(route('court-cases.index'))
        ->assertOk()
        ->assertSee('<html lang="'.$locale.'"', false);
})->with(['en', 'am']);

it('exposes no public, API or grievance route for the module', function (): void {
    $routes = courtCasesRoutes();

    expect($routes)->toHaveCount(1);

    foreach ($routes as $route) {
        $middleware = $route->gatherMiddleware();

        expect($route->uri())->toBe('court-cases')
            ->and($route->methods())->toBe(['GET', 'HEAD'])
            ->and($middleware)->toContain('auth')->toContain('mfa')->toContain('admin.access')
            ->and($route->uri())->not->toStartWith('api/')
            ->and($route->uri())->not->toStartWith('grievances');
    }

    // Grievance Management does not route into Court Cases.
    expect(courtCasesSource('routes/grievances.php'))->not->toContain('court');
});

it('creates no court case tables', function (): void {
    foreach (['court_cases', 'court_case_hearings', 'court_case_parties', 'court_case_decisions', 'court_case_appeals'] as $table) {
        expect(Schema::hasTable($table))->toBeFalse();
    }
});

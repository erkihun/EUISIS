<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Core\CoreNavigation;
use App\Services\Core\CoreWorkspace;
use App\Support\Rbac\DefaultRoleMatrix;
use App\Support\Rbac\PermissionCatalog;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
 * Stage 1 of the Core platform (docs/architecture/core-workspace.md): the
 * workspace is a session flag set at /core. It changes the navigation the
 * shell draws and nothing else; every page keeps its own permission and scope.
 */

function coreUser(array $permissions = []): User
{
    foreach (['core.access', ...$permissions] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $user = User::factory()->create();
    $user->givePermissionTo(['core.access', ...$permissions]);

    return $user;
}

/** @return list<string> every route the Core navigation offers in the shared props */
function coreNavRoutes(array $workspace): array
{
    return collect($workspace['nav'])->flatMap(fn (array $section) => collect($section['groups'])->flatMap(fn (array $group) => collect($group['items'])->pluck('route')))->values()->all();
}

it('sends a guest to sign in and refuses a person without core.access', function (): void {
    $this->get('/core')->assertRedirect(route('login'));

    Permission::findOrCreate('organizations.view', 'web');
    $staff = User::factory()->create();
    $staff->givePermissionTo('organizations.view');
    $this->actingAs($staff)->get('/core')->assertForbidden();
    expect(session(CoreWorkspace::SESSION_KEY))->toBeNull();
});

it('opens Core and switches the shell to Core navigation filtered by permission', function (): void {
    $user = coreUser(['organizations.view', 'cards.view']);

    $this->actingAs($user)->get(route('core.dashboard'))->assertOk()
        ->assertSessionHas(CoreWorkspace::SESSION_KEY, CoreWorkspace::NAME)
        ->assertInertia(fn (Assert $page) => $page->component('Core/Dashboard')
            ->where('workspace.name', 'core')
            ->where('workspace.nav', fn ($nav) => coreNavRoutes(['nav' => $nav]) === ['organizations.index', 'id-cards.index', 'id-cards.reprint-required', 'id-checker.index']));
});

it('offers no entry whose page the person may not open', function (): void {
    $user = coreUser();

    $this->actingAs($user)->get(route('core.dashboard'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('workspace.nav', [])->where('metrics.domains', []));
});

it('leaves every other page as it was until Core is opened, and after leaving it', function (): void {
    $user = coreUser(['organizations.view']);

    $before = $this->actingAs($user)->get(route('organizations.index'))->assertOk();
    $before->assertInertia(fn (Assert $page) => $page->component('Organizations/Index')->where('workspace', null));

    $this->get(route('core.dashboard'))->assertOk();
    // The same page, the same response; only the navigation follows the workspace.
    $this->get(route('organizations.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Organizations/Index')->where('workspace.name', 'core'));

    $this->post(route('core.exit'))->assertRedirect(route('dashboard'))->assertSessionMissing(CoreWorkspace::SESSION_KEY);
    $this->get(route('organizations.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Organizations/Index')->where('workspace', null));
});

it('drops the Core navigation as soon as core.access is withdrawn', function (): void {
    $user = coreUser(['organizations.view']);
    $this->actingAs($user)->get(route('core.dashboard'))->assertOk();

    $user->revokePermissionTo('core.access');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->get(route('organizations.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('workspace', null));
    $this->get(route('core.dashboard'))->assertForbidden();
});

it('has its own sign-in screen that uses the one login and lands in Core', function (): void {
    // ADR 0002, decision 3: a Core-titled sign-in over the same authentication, without self-registration.
    $this->get(route('core.login'))->assertOk()
        ->assertSessionHas('url.intended', route('core.dashboard'))
        ->assertInertia(fn (Assert $page) => $page->component('Auth/Login')->where('entry', 'core')->where('canResetPassword', true));

    $user = coreUser();
    $user->forceFill(['password' => bcrypt('Core-Sign-In-1'), 'status' => 'active', 'must_change_password' => false, 'password_changed_at' => now(), 'first_login_at' => now()])->save();
    $this->post(route('login'), ['email' => $user->email, 'password' => 'Core-Sign-In-1'])->assertRedirect(route('core.dashboard'));

    $this->get(route('core.login'))->assertRedirect(route('core.dashboard'));
});

it('is granted to the citywide administration roles only', function (): void {
    $roles = DefaultRoleMatrix::roles();
    $holders = collect($roles)->filter(fn (array $role) => in_array('core.access', $role['permissions'], true))->keys()->sort()->values()->all();

    expect(PermissionCatalog::names())->toContain('core.access')
        ->and($holders)->toBe(['City Admin', 'Public Service Bureau Admin', 'Super Admin', 'System Admin']);
});

it('names only icons the sidebar draws and labels that exist in English and Amharic', function (): void {
    $sections = (new ReflectionClassConstant(CoreNavigation::class, 'SECTIONS'))->getValue();
    $groups = collect($sections)->flatMap(fn (array $section) => $section['groups']);
    $items = $groups->flatMap(fn (array $group) => $group['items']);

    preg_match('/const CORE_ICONS[^{]*\{(.*?)\};/s', (string) file_get_contents(resource_path('js/Components/AppSidebar.tsx')), $match);
    $drawable = preg_split('/[\s,]+/', trim($match[1] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
    expect($groups->pluck('icon')->merge($items->pluck('icon'))->unique()->diff($drawable)->values()->all())->toBe([]);

    $labels = collect($sections)->pluck('label_key')->merge($groups->pluck('label_key'))->merge($items->pluck('label_key'))->unique();
    foreach (['en', 'am'] as $locale) {
        // `nav` merges the root dictionary with navigation.ts; Core keys live in core.ts.
        $sources = [
            'nav' => file_get_contents(resource_path("js/i18n/{$locale}.ts")).file_get_contents(resource_path("js/i18n/{$locale}/navigation.ts")),
            'core' => (string) file_get_contents(resource_path("js/i18n/{$locale}/core.ts")),
        ];
        $missing = $labels->reject(function (string $key) use ($sources): bool {
            $root = strstr($key, '.', true);
            $leaf = substr($key, strrpos($key, '.') + 1);

            return isset($sources[$root]) && preg_match('/^\s*'.preg_quote($leaf, '/').':\s/m', $sources[$root]) === 1;
        });
        expect($missing->values()->all())->toBe([], "Missing {$locale} labels");
    }
});

it('grants core.access to existing administration roles when migrated', function (): void {
    $admin = Role::findOrCreate('System Admin', 'web');
    $officer = Role::findOrCreate('HR Officer', 'web');
    $admin->revokePermissionTo(Permission::findOrCreate('core.access', 'web'));

    (require database_path('migrations/'.collect(scandir(database_path('migrations')))->first(fn (string $file) => str_ends_with($file, '_register_core_access_permission.php'))))->up();

    expect($admin->fresh()->hasPermissionTo('core.access'))->toBeTrue()
        ->and($officer->fresh()->hasPermissionTo('core.access'))->toBeFalse();
});

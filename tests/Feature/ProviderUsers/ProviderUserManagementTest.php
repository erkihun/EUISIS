<?php

declare(strict_types=1);

use App\Enums\AuditEventType;
use App\Models\AuditLog;
use App\Models\CafeteriaProvider;
use App\Models\CafeteriaProviderAssignment;
use App\Models\PasswordHistory;
use App\Models\Provider;
use App\Models\ProviderType;
use App\Models\ProviderUser;
use App\Models\ServiceProviderUser;
use App\Models\ServiceType;
use App\Models\User;
use App\Support\ProviderPortal\ProviderUserPermissionCatalog;
use App\Support\Rbac\DefaultRoleMatrix;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

const PU_NEW_PASSWORD = 'Harbor lantern violet 2026';

beforeEach(function (): void {
    config(['security.mfa_enforce' => false, 'security.passwords.breach_check.enabled' => false]);
});

/** @param list<string> $services */
function puProvider(array $services = ['cafeteria'], string $status = 'active'): Provider
{
    $type = ProviderType::query()->firstOrCreate(['code' => 'GENERAL'], ['name_en' => 'General', 'is_active' => true]);
    $provider = Provider::query()->create([
        'provider_code' => 'PU-'.Str::upper(Str::random(6)),
        'provider_type_id' => $type->id,
        'name_en' => 'Account Test Provider',
        'status' => $status,
    ]);

    foreach ($services as $code) {
        $serviceType = ServiceType::query()->firstOrCreate(['code' => $code], ['name_en' => Str::title($code), 'is_active' => true]);
        DB::table('provider_services')->insert([
            'id' => (string) Str::uuid7(), 'provider_id' => $provider->id, 'service_type_id' => $serviceType->id,
            'status' => 'active', 'enabled_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    if (in_array('cafeteria', $services, true)) {
        CafeteriaProvider::query()->create(['provider_id' => $provider->id, 'code' => $provider->provider_code, 'name_en' => 'Account Test Cafeteria', 'is_active' => true]);
    }

    return $provider;
}

/** @param list<string>|null $permissions null = Super Admin */
function puAdmin(?array $permissions = null): User
{
    $user = User::factory()->create(['status' => 'active', 'must_change_password' => false]);

    if ($permissions === null) {
        Role::findOrCreate('Super Admin', 'web');
        $user->assignRole('Super Admin');

        return $user;
    }

    foreach ($permissions as $name) {
        Permission::findOrCreate($name, 'web');
    }
    $user->givePermissionTo($permissions);

    return $user;
}

/** @return array<string, mixed> */
function puPayload(Provider $provider, array $overrides = []): array
{
    return [
        'provider_id' => $provider->id,
        'name' => 'Meron Tesfaye',
        'email' => 'meron.tesfaye@example.test',
        'username' => 'meron.t',
        'phone_number' => '0911 223 344',
        'provider_role' => 'operator',
        'portal_enabled' => true,
        'status' => 'active',
        'service_permissions' => [],
        'password' => '',
        ...$overrides,
    ];
}

function puAccount(Provider $provider, array $overrides = []): ProviderUser
{
    return ProviderUser::query()->create([
        'provider_id' => $provider->id,
        'name' => 'Abebe Kebede',
        'email' => 'abebe.k.'.Str::lower(Str::random(4)).'@example.test',
        'password' => Hash::make('Provider start phrase 77'),
        'provider_role' => 'operator',
        'status' => 'active',
        'portal_enabled' => true,
        ...$overrides,
    ]);
}

test('an account created on /provider-users signs in at the provider portal and must replace its password first', function (): void {
    $admin = puAdmin();
    $provider = puProvider(['cafeteria']);

    $this->actingAs($admin)->post(route('provider-users.store'), puPayload($provider))->assertSessionHasNoErrors();

    $temporary = (string) session('flash.temporary_password');
    $account = ProviderUser::query()->where('email', 'meron.tesfaye@example.test')->firstOrFail();

    expect($temporary)->not->toBe('')
        ->and($account->provider_id)->toBe($provider->id)
        ->and($account->provider_role)->toBe('operator')
        ->and($account->must_change_password)->toBeTrue()
        ->and($account->created_by)->toBe($admin->id)
        ->and(Hash::check($temporary, $account->password))->toBeTrue()
        ->and(AuditLog::query()->where('event_type', AuditEventType::ProviderUserCreated)->where('auditable_id', $account->id)->exists())->toBeTrue();

    // The provider signs in at /provider/portal/login ...
    $this->app['auth']->forgetGuards();
    $this->post(route('provider.portal.login.store'), ['identifier' => 'meron.tesfaye@example.test', 'password' => $temporary])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('provider.portal.dashboard'));

    // ... is held on the profile page until the one-time password is replaced ...
    $this->get(route('provider.portal.dashboard'))->assertRedirect(route('provider.portal.profile.show'));

    $this->patch(route('provider.portal.profile.password'), [
        'current_password' => $temporary, 'password' => PU_NEW_PASSWORD, 'password_confirmation' => PU_NEW_PASSWORD,
    ])->assertSessionHasNoErrors();

    // ... and then works normally, with the username too.
    expect($account->fresh()->must_change_password)->toBeFalse();
    $this->get(route('provider.portal.dashboard'))->assertOk();

    $this->post(route('provider.portal.logout'));
    $this->app['auth']->forgetGuards();
    $this->post(route('provider.portal.login.store'), ['identifier' => 'meron.t', 'password' => PU_NEW_PASSWORD])
        ->assertRedirect(route('provider.portal.dashboard'));
});

test('a typed initial password must pass the password policy and still has to be replaced', function (): void {
    $admin = puAdmin();
    $provider = puProvider(['transport']);

    $this->actingAs($admin)->post(route('provider-users.store'), puPayload($provider, ['password' => 'password']))
        ->assertSessionHasErrors('password');
    $this->actingAs($admin)->post(route('provider-users.store'), puPayload($provider, ['password' => 'Meron Tesfaye 2026 plan']))
        ->assertSessionHasErrors('password');

    $this->actingAs($admin)->post(route('provider-users.store'), puPayload($provider, ['password' => PU_NEW_PASSWORD]))
        ->assertSessionHasNoErrors()
        ->assertSessionMissing('flash.temporary_password');

    $account = ProviderUser::query()->where('username', 'meron.t')->firstOrFail();
    expect(Hash::check(PU_NEW_PASSWORD, $account->password))->toBeTrue()
        ->and($account->must_change_password)->toBeTrue();
});

test('sign-in names are validated and stay unique, deleted accounts included', function (): void {
    $admin = puAdmin();
    $provider = puProvider();
    $deleted = puAccount($provider, ['email' => 'taken@example.test', 'username' => 'taken_name']);
    $deleted->delete();

    $store = fn (array $overrides) => $this->actingAs($admin)->post(route('provider-users.store'), puPayload($provider, $overrides));

    $store(['email' => '', 'username' => ''])->assertSessionHasErrors(['email' => __('provider-users.email_or_username_required')]);
    $store(['email' => 'taken@example.test'])->assertSessionHasErrors('email');
    $store(['username' => 'taken_name'])->assertSessionHasErrors('username');
    $store(['username' => 'someone@example.test'])->assertSessionHasErrors('username');
    $store(['phone_number' => 'call me'])->assertSessionHasErrors('phone_number');
    $store(['provider_role' => 'admin'])->assertSessionHasErrors('provider_role');
    $store(['status' => 'suspended'])->assertSessionHasErrors('status');

    $gone = puProvider();
    $gone->delete();
    $store(['provider_id' => $gone->id])->assertSessionHasErrors('provider_id');

    expect(ProviderUser::query()->count())->toBe(0);
});

test('operators get only the permissions their provider offers; owners and managers need none', function (): void {
    $admin = puAdmin();
    $transport = puProvider(['transport']);
    $cafeteria = puProvider(['cafeteria']);

    $this->actingAs($admin)->post(route('provider-users.store'), puPayload($transport, [
        'service_permissions' => ['provider.transport.scan', 'provider.transport.reports.view'],
    ]))->assertSessionHasNoErrors();

    $operator = ProviderUser::query()->where('email', 'meron.tesfaye@example.test')->firstOrFail();
    expect($operator->servicePermissions()->pluck('permission_key')->sort()->values()->all())
        ->toBe(['provider.transport.reports.view', 'provider.transport.scan'])
        ->and($operator->servicePermissions()->first()->service_type_id)->toBe(ServiceType::query()->where('code', 'transport')->value('id'))
        ->and($operator->canUseServicePermission('provider.transport.scan'))->toBeTrue()
        ->and($operator->canUseServicePermission('provider.transport.routes.manage'))->toBeFalse();

    // Not offered by a cafeteria-only provider, or not a permission at all.
    $this->actingAs($admin)->post(route('provider-users.store'), puPayload($cafeteria, [
        'email' => 'second@example.test', 'username' => 'second', 'service_permissions' => ['provider.transport.scan'],
    ]))->assertSessionHasErrors('service_permissions');
    $this->actingAs($admin)->post(route('provider-users.store'), puPayload($transport, [
        'email' => 'third@example.test', 'username' => 'third', 'service_permissions' => ['users.delete'],
    ]))->assertSessionHasErrors('service_permissions.0');

    // A manager holds everything implicitly; keys sent along are not stored.
    $this->actingAs($admin)->post(route('provider-users.store'), puPayload($transport, [
        'email' => 'manager@example.test', 'username' => 'manager', 'provider_role' => 'manager',
        'service_permissions' => ['provider.transport.scan'],
    ]))->assertSessionHasNoErrors();
    $manager = ProviderUser::query()->where('email', 'manager@example.test')->firstOrFail();
    expect($manager->servicePermissions()->count())->toBe(0)
        ->and($manager->canUseServicePermission('provider.transport.routes.manage'))->toBeTrue();
});

test('editing keeps the provider, syncs permissions with the role and is audited without secrets', function (): void {
    $admin = puAdmin();
    $transport = puProvider(['transport']);
    $other = puProvider(['transport']);
    $account = puAccount($transport, ['email' => 'edit.me@example.test']);

    $update = fn (array $data) => $this->actingAs($admin)->patch(route('provider-users.update', $account), [
        'name' => 'Abebe K. Kebede', 'email' => 'edit.me@example.test', 'username' => 'abebe.k', 'phone_number' => '+251 911 000 111',
        'provider_role' => 'operator', 'portal_enabled' => true, 'service_permissions' => [], ...$data,
    ]);

    $update(['provider_id' => $other->id, 'service_permissions' => ['provider.transport.trips.manage']])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('provider-users.show', $account));

    $account->refresh();
    expect($account->provider_id)->toBe($transport->id)
        ->and($account->name)->toBe('Abebe K. Kebede')
        ->and($account->username)->toBe('abebe.k')
        ->and($account->servicePermissions()->pluck('permission_key')->all())->toBe(['provider.transport.trips.manage']);

    $update(['provider_role' => 'manager', 'service_permissions' => ['provider.transport.trips.manage']])->assertSessionHasNoErrors();
    expect($account->servicePermissions()->count())->toBe(0);

    $log = AuditLog::query()->where('event_type', AuditEventType::ProviderUserUpdated)->where('auditable_id', $account->id)->get()
        ->first(fn (AuditLog $entry) => ($entry->new_values['provider_role'] ?? null) === 'manager');
    expect($log)->not->toBeNull();
    expect($log->old_values['provider_role'])->toBe('operator')
        ->and($log->new_values['provider_role'])->toBe('manager')
        ->and(json_encode([$log->old_values, $log->new_values]))->not->toContain('password');
});

test('suspending ends portal access at the next request; activating restores it', function (): void {
    $admin = puAdmin();
    $provider = puProvider(['cafeteria']);
    $account = puAccount($provider, ['email' => 'suspend.me@example.test']);

    $this->actingAs($account, 'provider')->get(route('provider.portal.dashboard'))->assertOk();

    $this->actingAs($admin)->post(route('provider-users.suspend', $account), ['reason' => 'Left the company'])->assertSessionHasNoErrors();
    expect($account->fresh())
        ->status->toBe('suspended')
        ->suspension_reason->toBe('Left the company')
        ->suspended_by->toBe($admin->id);

    $this->actingAs($account->fresh(), 'provider')->get(route('provider.portal.dashboard'))->assertRedirect(route('provider.portal.login'));

    $this->app['auth']->forgetGuards();
    $this->post(route('provider.portal.login.store'), ['identifier' => 'suspend.me@example.test', 'password' => 'Provider start phrase 77'])
        ->assertSessionHasErrors('identifier');

    $this->actingAs($admin)->post(route('provider-users.activate', $account))->assertSessionHasNoErrors();
    expect($account->fresh())->status->toBe('active')->suspended_at->toBeNull()->suspension_reason->toBeNull();

    $this->app['auth']->forgetGuards();
    $this->post(route('provider.portal.login.store'), ['identifier' => 'suspend.me@example.test', 'password' => 'Provider start phrase 77'])
        ->assertSessionHasNoErrors();

    expect(AuditLog::query()->where('auditable_id', $account->id)->pluck('event_type')->map->value->all())
        ->toContain('provider_user.suspended', 'provider_user.activated');
});

test('an administrator reset issues a one-time password, keeps history and forces a change', function (): void {
    $admin = puAdmin();
    $account = puAccount(puProvider(['cafeteria']), ['email' => 'reset.me@example.test']);

    $this->actingAs($admin)->post(route('provider-users.reset-password', $account), ['password' => 'password'])
        ->assertSessionHasErrors('password');

    $this->actingAs($admin)->post(route('provider-users.reset-password', $account), ['password' => ''])->assertSessionHasNoErrors();
    $temporary = (string) session('flash.temporary_password');
    $account->refresh();

    expect($temporary)->not->toBe('')
        ->and(Hash::check($temporary, $account->password))->toBeTrue()
        ->and(Hash::check('Provider start phrase 77', $account->password))->toBeFalse()
        ->and($account->must_change_password)->toBeTrue()
        ->and(PasswordHistory::query()->where('authenticatable_type', $account->getMorphClass())->where('authenticatable_id', $account->id)->count())->toBe(1)
        ->and(AuditLog::query()->where('event_type', AuditEventType::TemporaryPasswordAssigned)->where('auditable_id', $account->id)->exists())->toBeTrue();

    // The previous password cannot be typed back in.
    $this->actingAs($admin)->post(route('provider-users.reset-password', $account), ['password' => 'Provider start phrase 77'])
        ->assertSessionHasErrors('password');
});

test('a deleted account cannot sign in, is listed separately and can be restored', function (): void {
    $admin = puAdmin();
    $account = puAccount(puProvider(['cafeteria']), ['email' => 'delete.me@example.test']);

    $this->actingAs($admin)->delete(route('provider-users.destroy', $account))->assertRedirect(route('provider-users.index'));
    expect($account->fresh()->trashed())->toBeTrue();

    $this->app['auth']->forgetGuards();
    $this->post(route('provider.portal.login.store'), ['identifier' => 'delete.me@example.test', 'password' => 'Provider start phrase 77'])
        ->assertSessionHasErrors('identifier');

    $this->actingAs($admin)->get(route('provider-users.index'))
        ->assertInertia(fn (Assert $page) => $page->component('ProviderUsers/Index')->has('rows', 0)->where('stats.deleted', 1));
    $this->actingAs($admin)->get(route('provider-users.index', ['trashed' => 'only']))
        ->assertInertia(fn (Assert $page) => $page->has('rows', 1)->where('rows.0.id', $account->id)->where('rows.0.can.restore', true)->where('rows.0.can.update', false));
    $this->actingAs($admin)->get(route('provider-users.show', $account))
        ->assertInertia(fn (Assert $page) => $page->component('ProviderUsers/Show')->where('account.can_sign_in', false)->where('can.restore', true));
    $this->actingAs($admin)->get(route('provider-users.edit', $account))->assertNotFound();

    $this->actingAs($admin)->post(route('provider-users.restore', $account))->assertRedirect(route('provider-users.show', $account));
    expect($account->fresh()->trashed())->toBeFalse();

    $this->app['auth']->forgetGuards();
    $this->post(route('provider.portal.login.store'), ['identifier' => 'delete.me@example.test', 'password' => 'Provider start phrase 77'])
        ->assertSessionHasNoErrors();

    expect(AuditLog::query()->where('auditable_id', $account->id)->pluck('event_type')->map->value->all())
        ->toContain('provider_user.deleted', 'provider_user.restored');
});

test('the list shows portal accounts with working filters', function (): void {
    $admin = puAdmin();
    $cafeteria = puProvider(['cafeteria']);
    $transport = puProvider(['transport']);
    puAccount($cafeteria, ['name' => 'Cafeteria Owner', 'provider_role' => 'owner']);
    puAccount($transport, ['name' => 'Transport Operator']);
    puAccount($transport, ['name' => 'Suspended Operator', 'status' => 'suspended']);

    $rows = fn (array $query) => $this->actingAs($admin)->get(route('provider-users.index', $query))->assertOk()->viewData('page')['props']['rows'];

    expect(collect($rows([]))->pluck('name')->all())->toBe(['Cafeteria Owner', 'Suspended Operator', 'Transport Operator'])
        ->and(collect($rows(['search' => $transport->provider_code]))->pluck('name')->all())->toBe(['Suspended Operator', 'Transport Operator'])
        ->and(collect($rows(['provider_id' => $cafeteria->id]))->pluck('name')->all())->toBe(['Cafeteria Owner'])
        ->and(collect($rows(['status' => 'suspended']))->pluck('name')->all())->toBe(['Suspended Operator'])
        ->and(collect($rows(['role' => 'owner']))->pluck('name')->all())->toBe(['Cafeteria Owner'])
        ->and(collect($rows(['service' => 'transport']))->pluck('name')->all())->toBe(['Suspended Operator', 'Transport Operator'])
        ->and(collect($rows(['status' => 'nonsense']))->count())->toBe(3);

    $this->actingAs($admin)->get(route('provider-users.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('stats.total', 3)->where('stats.active', 2)->where('stats.suspended', 1)
            ->where('can.create', true)
            ->missing('rows.0.password'));
});

test('every page and action needs its own permission', function (): void {
    $account = puAccount(puProvider());
    $nobody = puAdmin([]);
    $viewer = puAdmin(['cafeteria-provider-users.viewAny', 'cafeteria-provider-users.view']);

    $this->get(route('provider-users.index'))->assertRedirect(route('login'));

    $this->actingAs($nobody)->get(route('provider-users.index'))->assertForbidden();
    $this->actingAs($nobody)->get(route('provider-users.show', $account))->assertForbidden();

    $this->actingAs($viewer)->get(route('provider-users.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('can.create', false)->where('rows.0.can.update', false)->where('rows.0.can.suspend', false));
    $this->actingAs($viewer)->get(route('provider-users.show', $account))->assertOk();
    $this->actingAs($viewer)->get(route('provider-users.create'))->assertForbidden();
    $this->actingAs($viewer)->post(route('provider-users.store'), puPayload(puProvider()))->assertForbidden();
    $this->actingAs($viewer)->get(route('provider-users.edit', $account))->assertForbidden();
    $this->actingAs($viewer)->patch(route('provider-users.update', $account), ['name' => 'x'])->assertForbidden();
    $this->actingAs($viewer)->post(route('provider-users.suspend', $account))->assertForbidden();
    $this->actingAs($viewer)->post(route('provider-users.activate', $account))->assertForbidden();
    $this->actingAs($viewer)->post(route('provider-users.reset-password', $account))->assertForbidden();
    $this->actingAs($viewer)->delete(route('provider-users.destroy', $account))->assertForbidden();

    $account->delete();
    $this->actingAs($viewer)->post(route('provider-users.restore', $account))->assertForbidden();

    // The Cafeteria Admin role holds the full set.
    $cafeteriaAdmin = puAdmin(DefaultRoleMatrix::permissionsFor('Cafeteria Admin'));
    $this->actingAs($cafeteriaAdmin)->post(route('provider-users.restore', $account))->assertRedirect(route('provider-users.show', $account));

    // A provider portal session never reaches the admin pages.
    $this->app['auth']->forgetGuards();
    $this->post(route('provider.portal.login.store'), ['identifier' => $account->email, 'password' => 'Provider start phrase 77'])->assertSessionHasNoErrors();
    $this->get(route('provider-users.index'))->assertRedirect(route('login'));
});

test('legacy service provider accounts are migrated only when their provider is unambiguous', function (): void {
    $provider = puProvider(['cafeteria']);
    $location = CafeteriaProvider::query()->where('provider_id', $provider->id)->firstOrFail();
    $hash = Hash::make('Legacy start phrase 88');
    $legacy = fn (string $email) => ServiceProviderUser::query()->create([
        'name' => 'Legacy '.$email, 'email' => $email, 'password' => $hash,
        'status' => 'active', 'portal_enabled' => true, 'must_change_password' => false,
    ]);

    $mapped = $legacy('mapped@example.test');
    CafeteriaProviderAssignment::query()->create(['cafeteria_provider_id' => $location->id, 'service_provider_user_id' => $mapped->id, 'role' => 'operator', 'is_active' => true]);
    $legacy('orphan@example.test');
    $legacy('existing@example.test');
    puAccount($provider, ['email' => 'existing@example.test']);

    $this->artisan('provider-users:migrate-legacy')
        ->expectsOutputToContain('mapped@example.test')
        ->expectsOutputToContain('NEEDS_DECISION')
        ->assertSuccessful();
    expect(ProviderUser::query()->where('email', 'mapped@example.test')->exists())->toBeFalse();

    $this->artisan('provider-users:migrate-legacy --apply')->assertSuccessful();
    $migrated = ProviderUser::query()->where('email', 'mapped@example.test')->firstOrFail();
    expect($migrated->provider_id)->toBe($provider->id)
        ->and($migrated->provider_role)->toBe('operator')
        ->and($migrated->must_change_password)->toBeTrue()
        ->and($migrated->metadata['legacy_service_provider_user_id'])->toBe($mapped->id)
        ->and(Hash::check('Legacy start phrase 88', $migrated->password))->toBeTrue()
        ->and(ProviderUser::query()->where('email', 'orphan@example.test')->exists())->toBeFalse()
        ->and(ProviderUser::query()->where('email', 'existing@example.test')->count())->toBe(1);

    // Running it again changes nothing.
    $this->artisan('provider-users:migrate-legacy --apply')->assertSuccessful();
    expect(ProviderUser::query()->where('email', 'mapped@example.test')->count())->toBe(1);
});

test('the form pages offer each provider with its services and operator permissions', function (): void {
    $admin = puAdmin();
    $transport = puProvider(['transport']);
    $cafeteria = puProvider(['cafeteria']);
    $account = puAccount($transport);
    $account->servicePermissions()->create([
        'service_type_id' => ServiceType::query()->where('code', 'transport')->value('id'),
        'permission_key' => 'provider.transport.scan', 'is_allowed' => true,
    ]);

    $this->actingAs($admin)->get(route('provider-users.create', ['provider_id' => $transport->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('ProviderUsers/Form')
            ->where('account', null)
            ->where('defaults.provider_id', $transport->id)
            ->where('roles', ['owner', 'manager', 'operator'])
            ->where('providers', fn ($providers) => collect($providers)->firstWhere('id', $transport->id)['permissions'] === ProviderUserPermissionCatalog::KEYS_BY_SERVICE['transport']
                && collect($providers)->firstWhere('id', $cafeteria->id)['services'] === ['cafeteria']
                && collect($providers)->firstWhere('id', $cafeteria->id)['permissions'] === []));

    $this->actingAs($admin)->get(route('provider-users.edit', $account))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('ProviderUsers/Form')
            ->where('account.id', $account->id)
            ->where('account.provider_id', $transport->id)
            ->where('account.service_permissions', ['provider.transport.scan'])
            ->missing('account.password'));
});

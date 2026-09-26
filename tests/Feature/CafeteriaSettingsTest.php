<?php

declare(strict_types=1);

use App\Actions\Audit\WriteAuditLogAction;
use App\Actions\Cafeteria\UpdateCafeteriaSettingsAction;
use App\Enums\AuditEventType;
use App\Models\AuditLog;
use App\Models\CafeteriaProvider;
use App\Models\CafeteriaProviderAssignment;
use App\Models\CafeteriaProviderBranch;
use App\Models\CafeteriaSetting;
use App\Models\CafeteriaSubsidyRule;
use App\Models\ServiceProviderUser;
use App\Models\ServiceType;
use App\Models\User;
use App\Services\Cafeteria\CafeteriaSettingsService;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;

function cafeteriaSettingsUser(bool $canUpdate = true): User
{
    $user = User::factory()->create();
    foreach ($canUpdate ? ['cafeteria_settings.view', 'cafeteria_settings.update'] : ['cafeteria_settings.view'] as $name) {
        $user->givePermissionTo(Permission::findOrCreate($name, 'web'));
    }

    return $user;
}

test('cafeteria settings do not replace shared application settings', function (): void {
    app(CafeteriaSettingsService::class)->set('default_daily_subsidy_amount', 40);

    $this->actingAs(cafeteriaSettingsUser())->get(route('cafeteria.settings.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Cafeteria/Settings/Index')
            ->where('cafeteriaSettings.default_daily_subsidy_amount', 40)
            ->missing('settings.default_daily_subsidy_amount')
            ->has('settings'));
});

test('authorized settings updates persist typed values and an audit record', function (): void {
    $settings = app(CafeteriaSettingsService::class);
    $settings->set('block_cafeteria_during_employee_leave', true);

    $this->actingAs(cafeteriaSettingsUser())->from(route('cafeteria.settings.index'))
        ->put(route('cafeteria.settings.update'), [
            'block_cafeteria_during_employee_leave' => false,
            'leave_scan_mode' => 'reject',
        ])->assertRedirect(route('cafeteria.settings.index'))->assertSessionHasNoErrors();

    expect($settings->getBool('block_cafeteria_during_employee_leave'))->toBeFalse();
    expect(AuditLog::query()->where('event_type', AuditEventType::CafeteriaSettingsUpdated)->count())->toBe(1);
});

test('read only users cannot update cafeteria settings', function (): void {
    $this->actingAs(cafeteriaSettingsUser(false))->put(route('cafeteria.settings.update'), [
        'leave_scan_mode' => 'employee_payable',
    ])->assertForbidden();
});

test('invalid settings do not persist', function (): void {
    $this->actingAs(cafeteriaSettingsUser())->put(route('cafeteria.settings.update'), [
        'default_daily_subsidy_amount' => -5,
        'report_timezone' => 'invalid-timezone',
    ])->assertSessionHasErrors(['default_daily_subsidy_amount', 'report_timezone']);

    expect(CafeteriaSetting::query()->count())->toBe(0);
});

test('a failed settings audit rolls back all changed values', function (): void {
    $settings = app(CafeteriaSettingsService::class);
    $settings->set('leave_scan_mode', 'reject');
    $this->mock(WriteAuditLogAction::class)->shouldReceive('execute')->once()->andThrow(new RuntimeException('Audit unavailable'));

    expect(fn () => app(UpdateCafeteriaSettingsAction::class)->execute([
        'leave_scan_mode' => 'employee_payable',
    ], cafeteriaSettingsUser()))->toThrow(RuntimeException::class);

    expect(CafeteriaSetting::query()->where('key', 'leave_scan_mode')->firstOrFail()->value)->toBe('reject');
});

test('all settings have editability metadata and fixed rules show their effective values', function (): void {
    $settings = app(CafeteriaSettingsService::class);
    $settings->setMany(['allow_future_week_borrowing' => true, 'require_active_employee' => false]);
    // The global daily subsidy is read-only: financial values come only from
    // organization service policies (docs/cafeteria-policy-architecture.md).
    expect($settings->editableKeys())->toHaveCount(17)->not->toContain('default_daily_subsidy_amount');
    $this->actingAs(cafeteriaSettingsUser(false))->get(route('cafeteria.settings.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('editableSettingKeys', 17)->has('readOnlySettingReasons', 11)
            ->where('readOnlySettingReasons.default_daily_subsidy_amount', 'policyOnly')
            ->where('cafeteriaSettings.allow_future_week_borrowing', false)
            ->where('cafeteriaSettings.require_active_employee', true)
            ->where('providerUsers', [])->where('userOptions', []));
    expect($settings->getBool('allow_future_week_borrowing'))->toBeTrue();
});

test('fixed settings reject even null writes', function (string $key): void {
    $this->actingAs(cafeteriaSettingsUser())->put(route('cafeteria.settings.update'), [$key => null])
        ->assertSessionHasErrors($key);
    expect(CafeteriaSetting::query()->count())->toBe(0);
})->with(array_keys(CafeteriaSettingsService::READ_ONLY));

test('optional limits can be zero or cleared but operating settings cannot be null', function (): void {
    $this->actingAs(cafeteriaSettingsUser())->put(route('cafeteria.settings.update'), ['max_transaction_amount_per_scan' => 0, 'max_extra_amount_per_week' => null])->assertSessionHasNoErrors();
    expect((float) app(CafeteriaSettingsService::class)->get('max_transaction_amount_per_scan'))->toBe(0.0)
        ->and(app(CafeteriaSettingsService::class)->get('max_extra_amount_per_week'))->toBeNull();
    $this->put(route('cafeteria.settings.update'), ['week_start_day' => null, 'currency' => 'invalid'])->assertSessionHasErrors(['week_start_day', 'currency']);
});

test('provider assignment toggles preserve branch and effective dates and reject a mismatched branch', function (): void {
    $serviceType = ServiceType::query()->firstOrCreate(['code' => 'cafeteria'], ['name_en' => 'Cafeteria', 'is_active' => true]);
    $operator = ServiceProviderUser::query()->create(['service_type_id' => $serviceType->id, 'name' => 'Operator', 'username' => 'caf-operator', 'password' => 'test-password', 'status' => 'active', 'portal_enabled' => true]);
    $provider = CafeteriaProvider::query()->create(['code' => 'CAF-A', 'name_en' => 'Cafeteria A', 'is_active' => true]);
    $other = CafeteriaProvider::query()->create(['code' => 'CAF-B', 'name_en' => 'Cafeteria B', 'is_active' => true]);
    $branch = CafeteriaProviderBranch::query()->create(['cafeteria_provider_id' => $provider->id, 'code' => 'BR-A', 'name_en' => 'Branch A', 'is_active' => true]);
    $assignment = CafeteriaProviderAssignment::query()->create(['service_provider_user_id' => $operator->id, 'cafeteria_provider_id' => $provider->id, 'cafeteria_provider_branch_id' => $branch->id, 'is_active' => true, 'effective_from' => '2026-01-01', 'effective_to' => '2026-12-31']);
    $this->actingAs(cafeteriaSettingsUser())->patch(route('cafeteria.settings.provider-users.update', $assignment), ['is_active' => false])->assertSessionHasNoErrors();
    $assignment->refresh();
    expect($assignment->is_active)->toBeFalse()->and($assignment->cafeteria_provider_branch_id)->toBe($branch->id)
        ->and($assignment->effective_from->toDateString())->toBe('2026-01-01')->and($assignment->effective_to->toDateString())->toBe('2026-12-31');
    $this->patch(route('cafeteria.settings.provider-users.update', $assignment), ['is_active' => true, 'cafeteria_provider_id' => $other->id])->assertSessionHasErrors('cafeteria_provider_branch_id');
    expect($assignment->fresh()->cafeteria_provider_id)->toBe($provider->id);
});

test('new subsidy rules receive configured defaults without changing existing rules', function (): void {
    $user = cafeteriaSettingsUser();
    $user->givePermissionTo(Permission::findOrCreate('cafeteria_subsidy_rules.create', 'web'));
    app(CafeteriaSettingsService::class)->setMany(['default_daily_subsidy_amount' => 55, 'currency' => 'ETB']);
    $this->actingAs($user)->get(route('cafeteria.subsidy-rules.create'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('defaults.subsidy_amount', 55)->where('defaults.currency', 'ETB'));
    expect(CafeteriaSubsidyRule::query()->count())->toBe(0);
});

<?php

declare(strict_types=1);

use App\Models\SystemSetting;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Providers\Contracts\ReadModels\ProviderRecord;
use Modules\Providers\Domain\Models\Provider;
use Modules\Providers\Domain\Models\ProviderUser;
use Modules\Transport\Domain\Models\TransportPass;
use Modules\Transport\Domain\Models\TransportProviderProfile;
use Modules\Transport\Domain\Models\TransportRoute;
use Modules\Transport\Domain\Models\TransportTransaction;
use Tests\Support\TransportScenario;

/*
 * The staff side of transport: permissions on every write, organization scope
 * on everything that names an employee, and validation that keeps each record
 * with its own provider.
 */
beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-10-14 10:00'));
    $this->s = TransportScenario::make();
    $this->provider = $this->s->provider('Blue Line');
    $this->route = $this->s->route($this->provider);
    $this->admin = $this->s->staff(TransportScenario::permissions());
});

it('opens every admin transport page', function (string $name): void {
    $this->actingAs($this->admin)->get(route($name))->assertOk();
})->with(['transport.providers.index', 'transport.providers.create', 'transport.routes.index', 'transport.routes.create', 'transport.vehicles.index', 'transport.vehicles.create', 'transport.drivers.index', 'transport.drivers.create', 'transport.passes.index', 'transport.passes.create', 'transport.scan', 'transport.reports.index', 'transport.transactions.index', 'transport.settings.index']);

it('refuses every admin write without its permission, even beside a portal session', function (): void {
    $staff = $this->s->staff([]);
    $portalManager = $this->s->portalUser($this->provider, 'manager');

    // Both guards share one session: a portal sign-in must not lend its rights to admin routes.
    $this->actingAs($portalManager, 'provider')->actingAs($staff)->post(route('transport.routes.store'), [
        'provider_id' => $this->provider->id, 'route_code' => 'X1', 'name_en' => 'Sneaky', 'origin_en' => 'A', 'destination_en' => 'B', 'assigned_scope_type' => 'citywide',
    ])->assertForbidden();

    foreach ([['post', 'transport.vehicles.store', []], ['post', 'transport.drivers.store', []], ['post', 'transport.passes.store', []], ['post', 'transport.providers.store', []], ['patch', 'transport.settings.update', []]] as [$method, $name, $params]) {
        $this->actingAs($staff)->{$method}(route($name, $params), [])->assertForbidden();
    }

    expect(TransportRoute::query()->where('name_en', 'Sneaky')->exists())->toBeFalse();
});

it('requires a transport provider and a route organization unless the route is citywide', function (): void {
    $cafeteriaOnly = $this->s->provider('Lunch Co', ['cafeteria']);

    $this->actingAs($this->admin)->post(route('transport.routes.store'), [
        'provider_id' => $cafeteriaOnly->id, 'route_code' => 'R9', 'name_en' => 'R', 'origin_en' => 'A', 'destination_en' => 'B', 'assigned_scope_type' => 'self',
    ])->assertSessionHasErrors(['provider_id', 'assigned_organization_id']);

    $this->actingAs($this->admin)->post(route('transport.routes.store'), [
        'provider_id' => $this->provider->id, 'route_code' => 'R9', 'name_en' => 'Woreda shuttle', 'origin_en' => 'A', 'destination_en' => 'B',
        'assigned_scope_type' => 'subtree', 'assigned_organization_id' => $this->s->org['subcity_a']->id,
    ])->assertSessionHasNoErrors();

    expect(TransportRoute::query()->where('route_code', 'R9')->sole())
        ->assigned_scope_type->toBe('subtree')->assigned_organization_id->toBe($this->s->org['subcity_a']->id);
});

it("keeps a scoped admin's routes inside their organizations", function (): void {
    $scoped = $this->s->staff(['transport-routes.create'], 'subcity_a');

    $this->actingAs($scoped)->post(route('transport.routes.store'), [
        'provider_id' => $this->provider->id, 'route_code' => 'RB', 'name_en' => 'Other subcity', 'origin_en' => 'A', 'destination_en' => 'B',
        'assigned_scope_type' => 'self', 'assigned_organization_id' => $this->s->org['subcity_b']->id,
    ])->assertSessionHasErrors('assigned_organization_id');
});

it('will not move a record to another provider on edit', function (): void {
    $other = $this->s->provider('Red Line');

    $this->actingAs($this->admin)->patch(route('transport.routes.update', $this->route), [
        'provider_id' => $other->id, 'route_code' => $this->route->route_code, 'name_en' => 'Moved', 'origin_en' => 'A', 'destination_en' => 'B', 'assigned_scope_type' => 'citywide',
    ])->assertSessionHasErrors('provider_id');

    expect($this->route->fresh()->provider_id)->toBe($this->provider->id);
});

it('issues a pass only within scope, on the provider\'s own open route, without overlaps', function (): void {
    [$inside] = $this->s->employee('woreda_a1');
    [$outside] = $this->s->employee('subcity_b');
    $scoped = $this->s->staff(['transport-passes.create', 'transport-passes.viewAny'], 'subcity_a');
    $foreignRoute = $this->s->route($this->s->provider('Red Line'));
    $closedRoute = $this->s->route($this->provider, ['assigned_scope_type' => 'self', 'assigned_organization_id' => $this->s->org['subcity_b']->id]);
    $base = ['provider_id' => $this->provider->id, 'valid_from' => '2026-10-01', 'valid_until' => '2026-12-31', 'status' => 'active'];

    $this->actingAs($scoped)->post(route('transport.passes.store'), $base + ['employee_id' => $outside->id])->assertSessionHasErrors('employee_id');
    $this->actingAs($scoped)->post(route('transport.passes.store'), $base + ['employee_id' => $inside->id, 'transport_route_id' => $foreignRoute->id])->assertSessionHasErrors('transport_route_id');
    $this->actingAs($scoped)->post(route('transport.passes.store'), $base + ['employee_id' => $inside->id, 'transport_route_id' => $closedRoute->id])->assertSessionHasErrors('transport_route_id');

    $this->actingAs($scoped)->post(route('transport.passes.store'), $base + ['employee_id' => $inside->id, 'transport_route_id' => $this->route->id])->assertSessionHasNoErrors();
    $this->actingAs($scoped)->post(route('transport.passes.store'), $base + ['employee_id' => $inside->id, 'valid_from' => '2026-11-01'])->assertSessionHasErrors('valid_from');

    expect(TransportPass::query()->count())->toBe(1);

    // The list shows only passes of employees in scope.
    $this->s->pass($outside, $this->provider);
    $this->actingAs($scoped)->get(route('transport.passes.index'))->assertInertia(fn (Assert $page) => $page->where('passes.meta.total', 1));
});

it('does not ship an employee list with the pass form', function (): void {
    $this->actingAs($this->admin)->get(route('transport.passes.create'))
        ->assertInertia(fn (Assert $page) => $page->missing('employees')->has('providers')->has('routes'));
});

it('keeps fields an edit does not send', function (): void {
    $this->provider->forceFill(['phone_number' => '+251911000111', 'address' => 'Bole Road'])->save();
    TransportProviderProfile::query()->create(['provider_id' => $this->provider->id, 'license_number' => 'LIC-1', 'service_area_description_en' => 'Bole and Kirkos']);

    $this->actingAs($this->admin)->patch(route('transport.providers.update', $this->provider), [
        'provider_code' => $this->provider->provider_code, 'name_en' => 'Blue Line PLC', 'assigned_scope_type' => 'citywide', 'status' => 'active',
    ])->assertSessionHasNoErrors();

    $provider = ProviderRecord::query()->findOrFail($this->provider->id);
    expect($provider->name_en)->toBe('Blue Line PLC')
        ->and($provider->getAttribute('phone_number'))->toBe('+251911000111')
        ->and($provider->address)->toBe('Bole Road')
        ->and($provider->transportProfile->license_number)->toBe('LIC-1')
        ->and($provider->transportProfile->service_area_description_en)->toBe('Bole and Kirkos');
});

it('registers a provider with a portal account only when the account can sign in', function (): void {
    $payload = ['provider_code' => 'TRP-NEW', 'name_en' => 'Green Line', 'assigned_scope_type' => 'citywide', 'status' => 'active', 'create_provider_user' => true];

    $this->actingAs($this->admin)->post(route('transport.providers.store'), $payload)->assertSessionHasErrors(['user_name', 'user_email']);

    $this->actingAs($this->admin)->post(route('transport.providers.store'), $payload + ['user_name' => 'Hana', 'user_email' => 'hana@greenline.test'])
        ->assertSessionHasNoErrors()->assertSessionHas('flash.temporary_password');

    $provider = Provider::query()->where('provider_code', 'TRP-NEW')->sole();
    expect($provider->hasService('transport'))->toBeTrue()
        ->and(ProviderUser::query()->where('provider_id', $provider->id)->sole()->email)->toBe('hana@greenline.test');
});

it('will not delete a provider that also runs another service', function (): void {
    $shared = $this->s->provider('Bus and Lunch', ['transport', 'cafeteria']);

    $this->actingAs($this->admin)->delete(route('transport.providers.destroy', $shared))->assertSessionHas('flash.type', 'error');
    $this->actingAs($this->admin)->delete(route('transport.providers.destroy', $this->provider))->assertSessionHas('flash.type', 'success');

    expect($shared->fresh()->trashed())->toBeFalse()->and($this->provider->fresh()->trashed())->toBeTrue();
});

it('reports and exports boardings within the viewer\'s scope only', function (): void {
    [$inside, $insideCard] = $this->s->employee('woreda_a1');
    [$outside, $outsideCard] = $this->s->employee('subcity_b');
    foreach ([[$inside, $insideCard, 'accepted', 'transport_scan_accepted'], [$inside, $insideCard, 'rejected', 'active_transport_pass_missing'], [$outside, $outsideCard, 'accepted', 'transport_scan_accepted']] as [$employee, $card, $status, $code]) {
        TransportTransaction::query()->create([
            'provider_id' => $this->provider->id, 'employee_id' => $employee->id, 'id_card_id' => $card->id, 'transport_route_id' => $this->route->id,
            'scanned_at' => '2026-10-14 07:00', 'transaction_date' => '2026-10-14', 'status' => $status, 'result_code' => $code,
        ]);
    }
    $scoped = $this->s->staff(['transport-reports.view', 'transport-transactions.viewAny', 'transport-transactions.export'], 'subcity_a');

    $this->actingAs($scoped)->get(route('transport.reports.index'))->assertInertia(fn (Assert $page) => $page
        ->where('report.summary.total', 2)
        ->where('report.summary.accepted', 1)
        ->where('report.summary.riders', 1)
        ->where('report.rejections.0.code', 'active_transport_pass_missing')
        ->where('report.byProvider.0.accepted', 1)
        ->where('report.byRoute.0.route_code', $this->route->route_code));

    $this->actingAs($this->admin)->get(route('transport.reports.index'))->assertInertia(fn (Assert $page) => $page->where('report.summary.total', 3));

    $this->actingAs($scoped)->get(route('transport.transactions.index', ['status' => 'accepted']))
        ->assertInertia(fn (Assert $page) => $page->where('transactions.meta.total', 1));

    $csv = $this->actingAs($scoped)->get(route('transport.transactions.export'))->assertOk()->streamedContent();
    expect(substr_count(trim($csv), "\n"))->toBe(2)
        ->and($csv)->toContain($inside->employee_number)->not->toContain($outside->employee_number);
});

it('finds organizations for the forms within the caller\'s scope', function (): void {
    $scoped = $this->s->staff(['transport-routes.create'], 'subcity_a');

    $names = collect($this->actingAs($scoped)->getJson(route('transport.organizations.lookup', ['q' => 'Sub']))->assertOk()->json('data'))->pluck('name_en');

    expect($names->all())->toBe(['Subcity A']);
    $this->actingAs($this->s->staff([]))->getJson(route('transport.organizations.lookup', ['q' => 'Sub']))->assertForbidden();
});

it('saves the scan settings with labels in both languages', function (): void {
    $this->actingAs($this->admin)->patch(route('transport.settings.update'), [
        'require_pass_for_scan' => true, 'allow_pay_as_you_go' => true, 'scan_nonce_required' => true,
    ])->assertSessionHasNoErrors();

    $row = SystemSetting::query()->where('group', 'transport')->where('key', 'allow_pay_as_you_go')->sole();
    expect($row->value)->toBe('1')
        ->and($row->label_en)->toBe(__('transport.settings.allow_pay_as_you_go', [], 'en'))
        ->and($row->label_am)->toBe(__('transport.settings.allow_pay_as_you_go', [], 'am'));

    $this->actingAs($this->admin)->get(route('transport.settings.index'))
        ->assertInertia(fn (Assert $page) => $page->where('transportSettings.allow_pay_as_you_go', true));
});

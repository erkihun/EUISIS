<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\Support\CafeteriaScenario;

beforeEach(function (): void {
    config(['security.mfa_enforce' => false]);
    $this->s = CafeteriaScenario::make();
    $this->operator = User::factory()->create(['status' => 'active', 'must_change_password' => false]);
    $permissions = ['cafeteria_transactions.scan', 'cafeteria_providers.viewAll'];
    foreach ($permissions as $name) {
        Permission::findOrCreate($name, 'web');
    }
    $this->operator->givePermissionTo($permissions);
});

test('the mobile scanner opens on the provider named in the query', function (): void {
    $this->actingAs($this->operator)->get(route('cafeteria.scan.mobile', ['provider_id' => $this->s->branch->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Cafeteria/MobileScan')
            ->where('selected_provider_id', $this->s->branch->id));
});

test('the mobile scanner falls back to a visible provider when the query names another', function (): void {
    $visible = [$this->s->main->id, $this->s->branch->id];

    $this->actingAs($this->operator)->get(route('cafeteria.scan.mobile', ['provider_id' => (string) Str::uuid()]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('selected_provider_id', fn (string $id) => in_array($id, $visible, true)));
});

test('a scan returns to the screen it came from, keeping the mobile provider', function (): void {
    $scan = fn (array $extra) => $this->actingAs($this->operator)->post(route('cafeteria.scan.process'), [
        'provider_id' => $this->s->branch->id,
        'qr_token' => 'not-a-real-card-token',
        'scan_nonce' => (string) Str::uuid(),
        'usage_mode' => 'single_day',
        ...$extra,
    ]);

    $scan(['source' => 'mobile'])->assertRedirect(route('cafeteria.scan.mobile', ['provider_id' => $this->s->branch->id]));
    $scan([])->assertRedirect(route('cafeteria.scan'));
});

<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Features;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;

use function Pest\Laravel\actingAs;

/*
 * Transport Management is live by default and Court Cases is a placeholder
 * that stays off. A module whose flag is off is unreachable and unlisted, even
 * for an account that still holds its permissions; FEATURE_TRANSPORT=false
 * switches Transport off that way without touching its data.
 */

beforeEach(function (): void {
    Permission::findOrCreate('transport-providers.viewAny', 'web');
    Permission::findOrCreate('court_cases.view', 'web');
    $this->admin = User::factory()->create();
    $this->admin->givePermissionTo(['transport-providers.viewAny', 'court_cases.view']);
});

it('defaults Transport to enabled and Court Cases to disabled', function (): void {
    expect(Features::enabled('transport'))->toBeTrue()
        ->and(Features::enabled('court_cases'))->toBeFalse();
});

it('serves transport and answers 404 for court cases by default', function (): void {
    actingAs($this->admin)->get(route('transport.providers.index'))->assertOk();
    actingAs($this->admin)->get(route('court-cases.index'))->assertNotFound();
});

it('answers 404 for transport once its flag is switched off', function (): void {
    config(['features.transport' => false]);

    actingAs($this->admin)->get(route('transport.providers.index'))->assertNotFound();
    expect(Features::serviceAvailable('transport'))->toBeFalse();
});

it('shares the flags with the frontend', function (): void {
    // Without dashboard access the dashboard redirects to My Portal.
    $this->admin->givePermissionTo(Permission::findOrCreate('dashboard.view', 'web'));

    actingAs($this->admin)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('features.transport', true)
            ->where('features.court_cases', false));
});

it('treats transport as an available provider service while enabled', function (): void {
    expect(Features::serviceAvailable('transport'))->toBeTrue()
        ->and(Features::serviceAvailable('cafeteria'))->toBeTrue();
});

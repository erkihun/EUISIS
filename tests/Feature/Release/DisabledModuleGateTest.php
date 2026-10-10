<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Features;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;

/*
 * Transport Management is a future phase and Court Cases a placeholder: with
 * their flags off (the production default) they are unreachable and unlisted,
 * even for an account that still holds their permissions.
 */

beforeEach(function (): void {
    Permission::findOrCreate('transport-providers.viewAny', 'web');
    Permission::findOrCreate('court_cases.view', 'web');
    $this->admin = User::factory()->create();
    $this->admin->givePermissionTo(['transport-providers.viewAny', 'court_cases.view']);
});

it('defaults Transport and Court Cases to disabled', function (): void {
    expect(Features::enabled('transport'))->toBeFalse()
        ->and(Features::enabled('court_cases'))->toBeFalse();
});

it('answers 404 for transport and court-case routes while disabled', function (): void {
    actingAs($this->admin)->get(route('transport.providers.index'))->assertNotFound();
    actingAs($this->admin)->get(route('court-cases.index'))->assertNotFound();
});

it('serves the module again when its flag is enabled', function (): void {
    config(['features.transport' => true]);

    actingAs($this->admin)->get(route('transport.providers.index'))->assertOk();
});

it('shares the flags with the frontend', function (): void {
    actingAs($this->admin)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('features.transport', false)
            ->where('features.court_cases', false));
});

it('never treats a provider as offering a disabled module service', function (): void {
    expect(Features::serviceAvailable('transport'))->toBeFalse()
        ->and(Features::serviceAvailable('cafeteria'))->toBeTrue();
});

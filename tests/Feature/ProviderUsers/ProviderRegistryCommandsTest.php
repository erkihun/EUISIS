<?php

declare(strict_types=1);

use App\Models\User;
use Modules\Providers\Contracts\Data\ProviderRevision;
use Modules\Providers\Contracts\ProviderDirectory;
use Modules\Providers\Contracts\ProviderRegistry;
use Modules\Providers\Domain\Models\Provider;
use Tests\Support\TransportScenario;

/*
 * The Providers commands and queries other modules change provider records
 * through (ADR 0017): a partial revision, archiving, the services a provider runs.
 */

beforeEach(function (): void {
    $this->s = TransportScenario::make();
    $this->provider = $this->s->provider('Blue Line');
    $this->provider->forceFill(['phone_number' => '+251911000111', 'address' => 'Bole Road'])->save();
});

it('changes only the fields a revision names', function (): void {
    $actor = User::factory()->create();
    app(ProviderRegistry::class)->revise($this->provider->id, new ProviderRevision(['name_en' => 'Blue Line PLC', 'assigned_scope_type' => 'citywide', 'assigned_organization_id' => null], $actor->id));

    $provider = $this->provider->fresh();
    expect($provider->name_en)->toBe('Blue Line PLC')
        ->and($provider->assigned_scope_type)->toBe('citywide')
        ->and($provider->getAttribute('phone_number'))->toBe('+251911000111')
        ->and($provider->address)->toBe('Bole Road')
        ->and($provider->updated_by)->toBe($actor->id);
});

it('refuses a revision of a field that is not the provider\'s', function (): void {
    expect(fn () => new ProviderRevision(['provider_type_id' => 'x', 'metadata' => []], null))
        ->toThrow(InvalidArgumentException::class, 'provider_type_id, metadata');
});

it('archives a provider, which is then no longer found', function (): void {
    app(ProviderRegistry::class)->archive($this->provider->id);

    expect($this->provider->fresh()->trashed())->toBeTrue()
        ->and(app(ProviderDirectory::class)->find($this->provider->id))->toBeNull()
        ->and(Provider::withTrashed()->find($this->provider->id))->not->toBeNull();
});

it('lists the services a provider runs, an inactive one left out', function (): void {
    $shared = $this->s->provider('Bus and Lunch', ['transport', 'cafeteria']);
    $directory = app(ProviderDirectory::class);

    expect($directory->activeServiceCodes($shared->id))->toEqualCanonicalizing(['transport', 'cafeteria']);

    $shared->services()->whereHas('serviceType', fn ($q) => $q->where('code', 'cafeteria'))->update(['status' => 'inactive']);

    expect($directory->activeServiceCodes($shared->id))->toBe(['transport'])
        ->and($directory->activeServiceCodes($this->provider->id))->toBe(['transport']);
});

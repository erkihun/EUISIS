<?php

declare(strict_types=1);

use App\Enums\TransactionStatus;
use App\Models\ServiceProvider;
use App\Models\ServiceTransaction;
use App\Models\ServiceType;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    $permissions = [
        'service-providers.viewAny',
        'service-providers.view',
        'service-providers.create',
        'service-providers.update',
        'service-providers.delete',
    ];

    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    Role::findOrCreate('Provider Pages Admin', 'web')->syncPermissions($permissions);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('Provider Pages Admin');

    $this->cafeteria = ServiceType::query()->create(['code' => 'spp-cafeteria', 'name_en' => 'Cafeteria', 'is_active' => true]);
    $this->transport = ServiceType::query()->create(['code' => 'spp-transport', 'name_en' => 'Transport', 'is_active' => true]);
});

function sppProvider(ServiceType $type, string $code, string $status = 'active', string $name = ''): ServiceProvider
{
    return ServiceProvider::query()->create([
        'service_type_id' => $type->id,
        'name' => $name !== '' ? $name : "Provider {$code}",
        'code' => $code,
        'status' => $status,
        'is_demo' => false,
    ]);
}

function sppTransaction(ServiceProvider $provider, TransactionStatus $status, float $amount, int $daysAgo = 1): ServiceTransaction
{
    return ServiceTransaction::query()->create([
        'service_type_id' => $provider->service_type_id,
        'service_provider_id' => $provider->id,
        'status' => $status,
        'occurred_at' => now()->subDays($daysAgo),
        'reference' => 'TX-'.fake()->unique()->numerify('#####'),
        'amount' => $amount,
    ]);
}

it('summarises providers and recent activity on the list', function (): void {
    $canteen = sppProvider($this->cafeteria, 'CAF-01', name: 'Main Canteen');
    sppProvider($this->cafeteria, 'CAF-02', 'inactive');
    sppProvider($this->transport, 'BUS-01', 'suspended');

    sppTransaction($canteen, TransactionStatus::Settled, 45);
    sppTransaction($canteen, TransactionStatus::Denied, 45);
    // Outside the 30-day window: counted nowhere on this screen.
    sppTransaction($canteen, TransactionStatus::Settled, 45, daysAgo: 45);

    $this->actingAs($this->admin)
        ->get(route('service-providers.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('ServiceProviders/Index')
            ->where('stats.providers.all', 3)
            ->where('stats.providers.active', 1)
            ->where('stats.providers.inactive', 1)
            ->where('stats.providers.suspended', 1)
            ->where('stats.transactions_recent', 2)
            ->where('stats.denied_recent', 1)
            ->where('stats.service_types_in_use', 2)
            ->where('providers.data', function ($rows) use ($canteen): bool {
                $row = collect($rows)->firstWhere('id', $canteen->id);

                return $row['transactions_recent'] === 2 && $row['last_transaction_at'] !== null;
            })
        );
});

it('filters the list by search, service type and status', function (): void {
    sppProvider($this->cafeteria, 'CAF-01', name: 'Main Canteen');
    sppProvider($this->cafeteria, 'CAF-02', 'inactive', 'Annex Canteen');
    sppProvider($this->transport, 'BUS-01', name: 'Staff Bus');

    $codes = fn (array $query) => collect(
        $this->actingAs($this->admin)
            ->get(route('service-providers.index', $query))
            ->assertOk()
            ->viewData('page')['props']['providers']['data'],
    )->pluck('code')->sort()->values()->all();

    expect($codes(['search' => 'canteen']))->toBe(['CAF-01', 'CAF-02'])
        ->and($codes(['search' => 'bus-01']))->toBe(['BUS-01'])
        ->and($codes(['service_type_id' => $this->transport->id]))->toBe(['BUS-01'])
        ->and($codes(['status' => 'inactive']))->toBe(['CAF-02'])
        // Unknown values are ignored rather than trusted.
        ->and($codes(['status' => 'deleted', 'service_type_id' => 'not-a-uuid']))->toHaveCount(3);
});

it('counts statuses for the tabs without applying the status filter', function (): void {
    sppProvider($this->cafeteria, 'CAF-01');
    sppProvider($this->cafeteria, 'CAF-02', 'inactive');
    sppProvider($this->transport, 'BUS-01', 'inactive');

    $this->actingAs($this->admin)
        ->get(route('service-providers.index', ['status' => 'active', 'service_type_id' => $this->cafeteria->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('providers.data', fn ($rows) => count($rows) === 1)
            ->where('statusCounts.all', 2)
            ->where('statusCounts.active', 1)
            ->where('statusCounts.inactive', 1)
            ->where('statusCounts.suspended', 0)
        );
});

it('shows a provider with thirty-day figures that count only money that moved', function (): void {
    $provider = sppProvider($this->cafeteria, 'CAF-01');

    sppTransaction($provider, TransactionStatus::Settled, 45);
    sppTransaction($provider, TransactionStatus::Authorized, 25);
    sppTransaction($provider, TransactionStatus::Denied, 60);
    sppTransaction($provider, TransactionStatus::Reversed, 45);
    sppTransaction($provider, TransactionStatus::Settled, 100, daysAgo: 40);

    $this->actingAs($this->admin)
        ->get(route('service-providers.show', $provider))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('ServiceProviders/Show')
            ->where('stats.transactions_recent', 4)
            ->where('stats.denied_recent', 1)
            ->where('stats.processed_recent', fn ($amount) => (float) $amount === 70.0)
            ->where('stats.last_activity_at', fn ($value) => is_string($value))
            // The full history is not shipped inside the provider payload.
            ->missing('provider.transactions')
            ->has('transactions', 5)
        );
});

it('gives the form service types and organizations with their codes', function (): void {
    $provider = sppProvider($this->cafeteria, 'CAF-01');

    $this->actingAs($this->admin)
        ->get(route('service-providers.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('ServiceProviders/Create')
            ->where('serviceTypes', fn ($types) => collect($types)->pluck('code')->contains('spp-cafeteria'))
            ->has('organizations')
        );

    $this->actingAs($this->admin)
        ->get(route('service-providers.edit', $provider))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('ServiceProviders/Edit')
            ->where('provider.code', 'CAF-01')
            ->where('serviceTypes', fn ($types) => collect($types)->pluck('id')->contains($this->cafeteria->id))
        );
});

it('still creates, updates and deletes providers', function (): void {
    $this->actingAs($this->admin)
        ->post(route('service-providers.store'), [
            'name' => 'New Canteen',
            'code' => 'CAF-NEW',
            'service_type_id' => $this->cafeteria->id,
            'organization_id' => null,
            'status' => 'active',
            'is_demo' => true,
        ])
        ->assertRedirect();

    $provider = ServiceProvider::query()->where('code', 'CAF-NEW')->firstOrFail();
    expect($provider->is_demo)->toBeTrue();

    $this->actingAs($this->admin)
        ->patch(route('service-providers.update', $provider), [
            'name' => 'Renamed Canteen',
            'code' => 'CAF-NEW',
            'service_type_id' => $this->cafeteria->id,
            'organization_id' => null,
            'status' => 'suspended',
            'is_demo' => false,
        ])
        ->assertRedirect(route('service-providers.show', $provider));

    expect($provider->fresh()->status)->toBe('suspended');

    $this->actingAs($this->admin)
        ->delete(route('service-providers.destroy', $provider))
        ->assertRedirect(route('service-providers.index'));

    expect(ServiceProvider::query()->whereKey($provider->id)->exists())->toBeFalse();
});

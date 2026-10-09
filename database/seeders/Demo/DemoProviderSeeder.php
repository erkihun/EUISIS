<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Actions\Cafeteria\CreateCafeteriaProviderAction;
use App\Models\ProviderUser;
use App\Services\ProviderPortal\ProviderUserAccountService;
use App\Support\Demo\DemoDataset;
use Illuminate\Support\Facades\DB;

/**
 * Providers (payees) → networks → locations, created as Cafeteria
 * Management → Cafeterias → Add does it (CreateCafeteriaProviderAction):
 * the provider with its cafeteria service, a new network with its main
 * cafeteria, then branches and a service point placed under the main one.
 * Provider-portal accounts live in provider_users (the `provider` guard).
 */
class DemoProviderSeeder extends DemoSeeder
{
    public function run(CreateCafeteriaProviderAction $createCafeteria, ProviderUserAccountService $accounts): void
    {
        $maker = DemoDataset::requireUser(DemoDataset::MAKER_EMAIL);

        foreach (DemoDataset::networks() as $networkKey => $network) {
            DB::transaction(function () use ($networkKey, $network, $createCafeteria, $maker): void {
                $providerDefinition = DemoDataset::providers()[$network['provider']];

                foreach (DemoDataset::locations($networkKey) as $location => $definition) {
                    if (DemoDataset::cafeteria($networkKey, $location) !== null) {
                        continue;
                    }

                    $provider = DemoDataset::provider($network['provider']);
                    $existingNetwork = DemoDataset::network($networkKey);

                    $createCafeteria->execute([
                        'provider_mode' => $provider === null ? 'new' : 'existing',
                        'provider_id' => $provider?->id,
                        'provider_code' => $providerDefinition['code'],
                        'provider_name_en' => $providerDefinition['name_en'],
                        'provider_name_am' => $providerDefinition['name_am'],
                        'network_mode' => $existingNetwork === null ? 'new' : 'existing',
                        'cafeteria_service_network_id' => $existingNetwork?->id,
                        'network_code' => $network['code'],
                        'network_name_en' => $network['name_en'],
                        'network_name_am' => $network['name_am'],
                        'location_type' => $definition['type'],
                        'code' => DemoDataset::cafeteriaCode($networkKey, $location),
                        'name_en' => $definition['name_en'],
                        'name_am' => $definition['name_am'],
                        'operational_status' => 'open',
                        'opening_time' => '07:00',
                        'closing_time' => '19:00',
                        'capacity' => $definition['type'] === 'main' ? 200 : ($definition['type'] === 'branch' ? 80 : 20),
                        'is_active' => true,
                    ], $maker);
                }
            });
        }

        foreach (DemoDataset::providerUsers() as $email => $definition) {
            if (ProviderUser::query()->where('email', $email)->exists()) {
                continue;
            }

            [$account] = $accounts->create([
                'provider_id' => DemoDataset::provider($definition['provider'])->id,
                'name' => $definition['name'],
                'email' => $email,
                'username' => $definition['username'],
                'provider_role' => $definition['role'],
                'status' => 'active',
                'portal_enabled' => true,
                'service_permissions' => [],
                'password' => self::demoPassword(),
            ], $maker);

            // A shared, documented demo account: no forced change at first sign-in.
            $account->forceFill(['must_change_password' => false])->save();
        }
    }
}

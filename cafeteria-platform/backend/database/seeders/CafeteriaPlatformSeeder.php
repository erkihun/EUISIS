<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\ProviderType;
use App\Models\ServiceType;
use Illuminate\Database\Seeder;

/**
 * The reference rows the cafeteria module expects to exist: the `cafeteria`
 * service type, the CAFETERIA provider type, the working-day rules and the
 * default settings (the EUISIS seeders for the last two).
 */
class CafeteriaPlatformSeeder extends Seeder
{
    public function run(): void
    {
        ServiceType::query()->withTrashed()->firstOrCreate(['code' => 'cafeteria'], [
            'name_en' => 'Cafeteria',
            'name_am' => 'ካፌቴሪያ',
            'description_en' => 'Subsidized meals at the cafeterias serving an organization.',
            'is_active' => true,
            'module_key' => 'cafeteria',
            'route_prefix' => 'cafeteria',
            'icon' => 'CupHotIcon',
            'sort_order' => 10,
        ]);

        ProviderType::query()->withTrashed()->firstOrCreate(['code' => 'CAFETERIA'], [
            'name_en' => 'Cafeteria Provider',
            'name_am' => 'የካፌቴሪያ አቅራቢ',
            'is_active' => true,
            'sort_order' => 10,
        ]);

        $this->call([CafeteriaDayRuleSeeder::class, CafeteriaSettingsSeeder::class]);
    }
}

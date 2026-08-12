<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\OrganizationType;
use Illuminate\Database\Seeder;

class OrganizationTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            [
                'code' => 'CITY_ADMIN',
                'name_en' => 'City Administration',
                'name_am' => 'የከተማ አስተዳደር',
                'level_order' => 1,
                'category' => 'root',
                'parent_allowed_types' => [],
                'is_active' => true,
            ],
            [
                'code' => 'BUREAU',
                'name_en' => 'Bureau',
                'name_am' => 'ቢሮ',
                'level_order' => 2,
                'category' => 'functional',
                'parent_allowed_types' => ['CITY_ADMIN'],
                'is_active' => true,
            ],
            [
                'code' => 'AUTHORITY',
                'name_en' => 'Authority',
                'name_am' => 'ባለስልጣን',
                'level_order' => 2,
                'category' => 'functional',
                'parent_allowed_types' => ['CITY_ADMIN'],
                'is_active' => true,
            ],
            [
                'code' => 'COMMISSION',
                'name_en' => 'Commission',
                'name_am' => 'ኮሚሽን',
                'level_order' => 2,
                'category' => 'functional',
                'parent_allowed_types' => ['CITY_ADMIN'],
                'is_active' => true,
            ],
            [
                'code' => 'AGENCY',
                'name_en' => 'Agency',
                'name_am' => 'ኤጀንሲ',
                'level_order' => 2,
                'category' => 'functional',
                'parent_allowed_types' => ['CITY_ADMIN', 'BUREAU'],
                'is_active' => true,
            ],
            [
                'code' => 'SUB_CITY',
                'name_en' => 'Sub-City',
                'name_am' => 'ክፍለ ከተማ',
                'level_order' => 2,
                'category' => 'geographic',
                'parent_allowed_types' => ['CITY_ADMIN'],
                'is_active' => true,
            ],
            [
                'code' => 'WOREDA',
                'name_en' => 'Woreda',
                'name_am' => 'ወረዳ',
                'level_order' => 3,
                'category' => 'geographic',
                'parent_allowed_types' => ['SUB_CITY'],
                'is_active' => true,
            ],
            [
                'code' => 'BRANCH',
                'name_en' => 'Branch Office',
                'name_am' => 'ቅርንጫፍ ቢሮ',
                'level_order' => 3,
                'category' => 'geographic',
                'parent_allowed_types' => ['BUREAU', 'SUB_CITY', 'WOREDA'],
                'is_active' => true,
            ],
            [
                'code' => 'POOL',
                'name_en' => 'Pool',
                'name_am' => 'ፑል',
                'level_order' => 3,
                'category' => 'functional',
                'parent_allowed_types' => ['BUREAU', 'AUTHORITY', 'COMMISSION'],
                'is_active' => true,
            ],
            [
                'code' => 'PROVIDER',
                'name_en' => 'Service Provider',
                'name_am' => 'አገልግሎት አቅራቢ',
                'level_order' => 4,
                'category' => 'service_provider',
                'parent_allowed_types' => ['CITY_ADMIN', 'BUREAU', 'SUB_CITY', 'WOREDA'],
                'is_active' => true,
            ],
            [
                'code' => 'INDEPENDENT_INSTITUTION',
                'name_en' => 'Independent Institution',
                'name_am' => 'ነጻ ተቋም',
                'level_order' => 2,
                'category' => 'independent',
                'parent_allowed_types' => null,
                'is_active' => true,
            ],
        ];

        foreach ($types as $data) {
            OrganizationType::withTrashed()->updateOrCreate(
                ['code' => $data['code']],
                array_merge($data, ['deleted_at' => null]),
            );
        }
    }
}

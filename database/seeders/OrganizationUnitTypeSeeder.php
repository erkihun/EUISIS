<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\OrganizationUnitType;
use Illuminate\Database\Seeder;

class OrganizationUnitTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            ['code' => 'department',  'name_en' => 'Department',  'name_am' => 'መምሪያ',        'sort_order' => 1],
            ['code' => 'directorate', 'name_en' => 'Directorate', 'name_am' => 'ዳይሬክቶሬት',    'sort_order' => 2],
            ['code' => 'team',        'name_en' => 'Team',        'name_am' => 'ቡድን',          'sort_order' => 3],
            ['code' => 'unit',        'name_en' => 'Unit',        'name_am' => 'ክፍል',          'sort_order' => 4],
            ['code' => 'office',      'name_en' => 'Office',      'name_am' => 'ጽ/ቤት',        'sort_order' => 5],
            ['code' => 'section',     'name_en' => 'Section',     'name_am' => 'ንዑስ ክፍል',     'sort_order' => 6],
        ];

        foreach ($types as $type) {
            OrganizationUnitType::withTrashed()->firstOrCreate(
                ['code' => $type['code']],
                array_merge($type, ['is_active' => true]),
            );
        }
    }
}

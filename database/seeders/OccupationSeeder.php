<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Occupation;
use Illuminate\Database\Seeder;

class OccupationSeeder extends Seeder
{
    public function run(): void
    {
        $examples = [
            ['isco_code' => '2512', 'isco_major_group_code' => '2', 'isco_sub_major_group_code' => '25', 'isco_minor_group_code' => '251', 'isco_unit_group_code' => '2512', 'name_en' => 'Software Developers', 'skill_level' => '4'],
            ['isco_code' => '2423', 'isco_major_group_code' => '2', 'isco_sub_major_group_code' => '24', 'isco_minor_group_code' => '242', 'isco_unit_group_code' => '2423', 'name_en' => 'Personnel and Careers Professionals', 'skill_level' => '4'],
            ['isco_code' => '2611', 'isco_major_group_code' => '2', 'isco_sub_major_group_code' => '26', 'isco_minor_group_code' => '261', 'isco_unit_group_code' => '2611', 'name_en' => 'Lawyers', 'skill_level' => '4'],
            ['isco_code' => '2411', 'isco_major_group_code' => '2', 'isco_sub_major_group_code' => '24', 'isco_minor_group_code' => '241', 'isco_unit_group_code' => '2411', 'name_en' => 'Accountants', 'skill_level' => '4'],
            ['isco_code' => '3353', 'isco_major_group_code' => '3', 'isco_sub_major_group_code' => '33', 'isco_minor_group_code' => '335', 'isco_unit_group_code' => '3353', 'name_en' => 'Government Social Benefits Officials', 'skill_level' => '3'],
            ['isco_code' => '4110', 'isco_major_group_code' => '4', 'isco_sub_major_group_code' => '41', 'isco_minor_group_code' => '411', 'isco_unit_group_code' => '4110', 'name_en' => 'General Office Clerks', 'skill_level' => '2'],
            ['isco_code' => '8322', 'isco_major_group_code' => '8', 'isco_sub_major_group_code' => '83', 'isco_minor_group_code' => '832', 'isco_unit_group_code' => '8322', 'name_en' => 'Car, Taxi and Van Drivers', 'skill_level' => '2'],
        ];

        foreach ($examples as $example) {
            Occupation::query()->updateOrCreate(
                ['isco_code' => $example['isco_code']],
                array_merge($example, [
                    'code' => $example['isco_code'],
                    'is_active' => true,
                    'sort_order' => 0,
                ]),
            );
        }
    }
}

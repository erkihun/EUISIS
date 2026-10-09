<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\IsicActivity;
use Illuminate\Database\Seeder;

class IsicActivitySeeder extends Seeder
{
    public function run(): void
    {
        $examples = [
            ['isic_code' => 'O', 'level' => 'section', 'section_code' => 'O', 'name_en' => 'Public Administration and Defence; Compulsory Social Security'],
            ['isic_code' => '84', 'level' => 'division', 'section_code' => 'O', 'division_code' => '84', 'name_en' => 'Public Administration and Defence; Compulsory Social Security'],
            ['isic_code' => '841', 'level' => 'group', 'section_code' => 'O', 'division_code' => '84', 'group_code' => '841', 'name_en' => 'Administration of the State and the Economic and Social Policy of the Community'],
            ['isic_code' => '8411', 'level' => 'class', 'section_code' => 'O', 'division_code' => '84', 'group_code' => '841', 'class_code' => '8411', 'name_en' => 'General Public Administration Activities'],
        ];

        foreach ($examples as $example) {
            IsicActivity::query()->updateOrCreate(
                ['isic_code' => $example['isic_code']],
                array_merge($example, ['is_active' => true, 'sort_order' => 0]),
            );
        }
    }
}

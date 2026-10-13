<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\CompetencyLevel;
use App\Models\CompetencyType;
use App\Models\EducationLevel;
use App\Models\ResponsibilityType;
use Illuminate\Database\Seeder;

/**
 * Starting data for the organization-design catalogs, not policy: the
 * competency types the brief names, five named competency levels mapped to
 * the EPMS 1–5 competency scale (D-13), and a generic qualification ladder
 * (NEEDS_DECISION: align codes and names with the civil service
 * qualification ladder before go-live). Idempotent; stewards edit the rest.
 */
class OrganizationDesignCatalogSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['code' => 'RESPONSIBLE', 'name_en' => 'Responsible', 'name_am' => 'ኃላፊ', 'is_exclusive' => false, 'covers_object' => true, 'sort_order' => 10],
            ['code' => 'ACCOUNTABLE', 'name_en' => 'Accountable', 'name_am' => 'ተጠያቂ', 'is_exclusive' => true, 'covers_object' => true, 'sort_order' => 20],
            ['code' => 'CONSULTED', 'name_en' => 'Consulted', 'name_am' => 'የሚማከር', 'is_exclusive' => false, 'covers_object' => false, 'sort_order' => 30],
            ['code' => 'SUPPORTING', 'name_en' => 'Supporting', 'name_am' => 'ደጋፊ', 'is_exclusive' => false, 'covers_object' => false, 'sort_order' => 40],
        ] as $type) {
            ResponsibilityType::query()->updateOrCreate(['code' => $type['code']], $type);
        }

        foreach ([
            ['code' => 'CORE', 'name_en' => 'Core', 'name_am' => 'ዋና', 'sort_order' => 10],
            ['code' => 'TECHNICAL', 'name_en' => 'Technical', 'name_am' => 'ቴክኒካል', 'sort_order' => 20],
            ['code' => 'LEADERSHIP', 'name_en' => 'Leadership', 'name_am' => 'አመራር', 'sort_order' => 30],
            ['code' => 'BEHAVIOURAL', 'name_en' => 'Behavioural', 'name_am' => 'ባህሪያዊ', 'sort_order' => 40],
        ] as $type) {
            CompetencyType::query()->updateOrCreate(['code' => $type['code']], $type);
        }

        foreach ([
            ['code' => 'FOUNDATIONAL', 'name_en' => 'Foundational', 'name_am' => 'መሠረታዊ', 'rank' => 1],
            ['code' => 'DEVELOPING', 'name_en' => 'Developing', 'name_am' => 'በማደግ ላይ', 'rank' => 2],
            ['code' => 'PROFICIENT', 'name_en' => 'Proficient', 'name_am' => 'ብቁ', 'rank' => 3],
            ['code' => 'ADVANCED', 'name_en' => 'Advanced', 'name_am' => 'የላቀ', 'rank' => 4],
            ['code' => 'EXPERT', 'name_en' => 'Expert', 'name_am' => 'ባለሙያ', 'rank' => 5],
        ] as $level) {
            CompetencyLevel::query()->updateOrCreate(['code' => $level['code']], $level);
        }

        foreach ([
            ['code' => 'PRIMARY', 'name_en' => 'Primary education', 'name_am' => 'የመጀመሪያ ደረጃ ትምህርት', 'rank' => 1],
            ['code' => 'SECONDARY', 'name_en' => 'Secondary education', 'name_am' => 'የሁለተኛ ደረጃ ትምህርት', 'rank' => 2],
            ['code' => 'CERTIFICATE', 'name_en' => 'Certificate', 'name_am' => 'ሰርተፍኬት', 'rank' => 3],
            ['code' => 'DIPLOMA', 'name_en' => 'Diploma', 'name_am' => 'ዲፕሎማ', 'rank' => 4],
            ['code' => 'ADVANCED_DIPLOMA', 'name_en' => 'Advanced diploma', 'name_am' => 'የላቀ ዲፕሎማ', 'rank' => 5],
            ['code' => 'BACHELOR', 'name_en' => 'Bachelor degree', 'name_am' => 'የመጀመሪያ ዲግሪ', 'rank' => 6],
            ['code' => 'MASTER', 'name_en' => 'Master degree', 'name_am' => 'ሁለተኛ ዲግሪ', 'rank' => 7],
            ['code' => 'DOCTORATE', 'name_en' => 'Doctorate', 'name_am' => 'ዶክትሬት', 'rank' => 8],
        ] as $level) {
            EducationLevel::query()->updateOrCreate(['code' => $level['code']], $level);
        }
    }
}

<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\LegalInstrumentType;
use Illuminate\Database\Seeder;

/** The legal instruments a mandate can rest on (brief §4). Idempotent. */
class LegalInstrumentTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            ['code' => 'PROCLAMATION', 'name_en' => 'Proclamation', 'name_am' => 'አዋጅ', 'sort_order' => 10],
            ['code' => 'REGULATION', 'name_en' => 'Regulation', 'name_am' => 'ደንብ', 'sort_order' => 20],
            ['code' => 'DIRECTIVE', 'name_en' => 'Directive', 'name_am' => 'መመሪያ', 'sort_order' => 30],
            ['code' => 'COUNCIL_DECISION', 'name_en' => 'Council decision', 'name_am' => 'የምክር ቤት ውሳኔ', 'sort_order' => 40],
            ['code' => 'CABINET_DECISION', 'name_en' => 'Cabinet decision', 'name_am' => 'የካቢኔ ውሳኔ', 'sort_order' => 50],
            ['code' => 'CHARTER', 'name_en' => 'Charter', 'name_am' => 'ቻርተር', 'sort_order' => 60],
            // Sources a legacy baseline rests on (docs/organization-design-legacy-baseline-plan.md §8).
            ['code' => 'ADMINISTRATIVE_DECISION', 'name_en' => 'Administrative decision', 'name_am' => 'አስተዳደራዊ ውሳኔ', 'sort_order' => 65],
            ['code' => 'APPROVED_STRUCTURE', 'name_en' => 'Approved structure', 'name_am' => 'የጸደቀ መዋቅር', 'sort_order' => 70],
            ['code' => 'BPR_STUDY', 'name_en' => 'BPR study', 'name_am' => 'የሥራ ሂደት ለውጥ (BPR) ጥናት', 'sort_order' => 75],
            ['code' => 'MANUAL', 'name_en' => 'Manual', 'name_am' => 'ማኑዋል', 'sort_order' => 80],
            ['code' => 'POLICY', 'name_en' => 'Policy', 'name_am' => 'ፖሊሲ', 'sort_order' => 85],
            ['code' => 'OTHER', 'name_en' => 'Other', 'name_am' => 'ሌላ', 'sort_order' => 90],
        ];

        foreach ($types as $type) {
            LegalInstrumentType::query()->updateOrCreate(['code' => $type['code']], $type);
        }
    }
}

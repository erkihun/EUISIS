<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\FieldWorkType;
use Illuminate\Database\Seeder;

/** Editable starter catalog; it contains examples, not permanent HR policy. */
class FieldWorkTypeSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['INSPECTION', 'Inspection', 'ምርመራ'], ['SUPERVISION', 'Supervision', 'ክትትል'], ['TECHNICAL_SUPPORT', 'Technical Support', 'ቴክኒካል ድጋፍ'], ['MONITORING', 'Monitoring', 'ክትትል'], ['SITE_VISIT', 'Site Visit', 'የቦታ ጉብኝት'], ['FIELD_VERIFICATION', 'Field Verification', 'የመስክ ማረጋገጫ'], ['OFFICIAL_MEETING', 'Official Meeting', 'ኦፊሴላዊ ስብሰባ'], ['SERVICE_DELIVERY', 'Service Delivery', 'አገልግሎት አሰጣጥ'], ['PROJECT_FOLLOW_UP', 'Project Follow-up', 'የፕሮጀክት ክትትል'], ['OTHER_OFFICIAL_FIELD_WORK', 'Other Official Field Work', 'ሌላ ኦፊሴላዊ የመስክ ሥራ'],
        ] as [$code, $nameEn, $nameAm]) {
            FieldWorkType::query()->firstOrCreate(['code' => $code], ['name_en' => $nameEn, 'name_am' => $nameAm, 'is_active' => true]);
        }
    }
}

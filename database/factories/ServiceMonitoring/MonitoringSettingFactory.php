<?php

declare(strict_types=1);

namespace Database\Factories\ServiceMonitoring;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\ServiceMonitoring\Domain\Models\MonitoringSetting;

/** @extends Factory<MonitoringSetting> */
class MonitoringSettingFactory extends Factory
{
    protected $model = MonitoringSetting::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'require_stamped_copy' => false,
            'declaration_text_en' => 'I declare that this report is complete and correct.',
            'declaration_text_am' => 'ይህ ሪፖርት የተሟላና ትክክለኛ መሆኑን አረጋግጣለሁ።',
        ];
    }
}

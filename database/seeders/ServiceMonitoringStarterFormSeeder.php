<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ServiceMonitoring\FormStatus;
use App\Models\ServiceMonitoringForm;
use App\Support\ServiceMonitoring\FormSchema;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Seeds the starter monitoring form (from the bureau's Word templates) as a
 * draft. A form whose code already exists is never touched: once seeded,
 * administrators own it.
 */
class ServiceMonitoringStarterFormSeeder extends Seeder
{
    public function run(): void
    {
        foreach (glob(database_path('seeders/data/service-monitoring-forms/*.php')) ?: [] as $file) {
            $definition = require $file;
            if (ServiceMonitoringForm::query()->where('code', $definition['form']['code'])->exists()) {
                continue;
            }
            $problems = FormSchema::from($definition['schema'])->problems(forPublish: true);
            if ($problems !== []) {
                throw new RuntimeException('Invalid starter form '.basename($file).': '.json_encode($problems));
            }
            ServiceMonitoringForm::query()->create($definition['form'] + [
                'version' => 1,
                'status' => FormStatus::Draft,
                'schema' => $definition['schema'],
            ]);
        }
    }
}

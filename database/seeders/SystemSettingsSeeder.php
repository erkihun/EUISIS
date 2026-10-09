<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\SystemSetting;
use App\Services\SystemSettings\SystemSettingsRegistry;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class SystemSettingsSeeder extends Seeder
{
    public function run(): void
    {
        $hasIsSystem = Schema::hasColumn('system_settings', 'is_system');
        $hasOptions = Schema::hasColumn('system_settings', 'options');
        $hasValidationRules = Schema::hasColumn('system_settings', 'validation_rules');
        $hasDefaultValue = Schema::hasColumn('system_settings', 'default_value');
        $hasIsRequired = Schema::hasColumn('system_settings', 'is_required');

        foreach (SystemSettingsRegistry::definitions() as $group => $fields) {
            foreach ($fields as $key => $def) {
                $isEncrypted = (bool) ($def['is_encrypted'] ?? false);
                $default = $def['default'] ?? null;

                $value = null;
                if (! $isEncrypted && $default !== null) {
                    $value = match ($def['type']) {
                        'boolean' => $default ? 'true' : 'false',
                        'integer' => (string) (int) $default,
                        'json', 'multiselect' => json_encode($default),
                        default => is_string($default) ? $default : (string) $default,
                    };
                }

                $metadata = [
                    'type' => $def['type'],
                    'label_en' => $def['label_en'] ?? null,
                    'label_am' => $def['label_am'] ?? null,
                    'description_en' => $def['description_en'] ?? null,
                    'description_am' => $def['description_am'] ?? null,
                    'is_public' => (bool) ($def['is_public'] ?? false),
                    'is_encrypted' => $isEncrypted,
                    'sort_order' => (int) ($def['sort_order'] ?? 0),
                ];

                if ($hasIsSystem) {
                    $metadata['is_system'] = true;
                }

                if ($hasOptions) {
                    $metadata['options'] = $def['options'] ?? null;
                }

                if ($hasValidationRules) {
                    $metadata['validation_rules'] = $def['validation_rules'] ?? null;
                }

                if ($hasDefaultValue) {
                    $metadata['default_value'] = match ($def['type']) {
                        'boolean' => $default === null ? null : ($default ? 'true' : 'false'),
                        'integer' => $default === null ? null : (string) (int) $default,
                        'json', 'multiselect' => $default === null ? null : json_encode($default),
                        default => is_string($default) ? $default : ($default === null ? null : (string) $default),
                    };
                }

                if ($hasIsRequired) {
                    $metadata['is_required'] = (bool) ($def['is_required'] ?? false);
                }

                /** @var SystemSetting $setting */
                $setting = SystemSetting::query()->firstOrNew(['group' => $group, 'key' => $key]);

                if (! $setting->exists) {
                    $setting->id = (string) Str::uuid7();
                    $setting->value = $value;
                }

                $setting->fill($metadata);
                $setting->save();
            }
        }

        // Invalidate any cached settings.
        Cache::forget('system_settings_all');
        Cache::forget('system_settings_public');
    }
}

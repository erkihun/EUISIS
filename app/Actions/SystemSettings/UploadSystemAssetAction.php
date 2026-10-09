<?php

declare(strict_types=1);

namespace App\Actions\SystemSettings;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\SystemSettings\SystemSettingsRegistry;
use App\Services\SystemSettings\SystemSettingsService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class UploadSystemAssetAction
{
    public function __construct(
        private readonly WriteAuditLogAction $writeAuditLogAction,
        private readonly SystemSettingsService $settingsService,
    ) {}

    public function execute(string $key, UploadedFile $file, User $actor): SystemSetting
    {
        $definition = SystemSettingsRegistry::definition(SystemSettingsRegistry::GROUP_GENERAL, $key);

        if ($definition === null) {
            throw new \InvalidArgumentException("Unknown system setting asset key [{$key}].");
        }

        $existing = SystemSetting::query()->firstOrNew([
            'group' => SystemSettingsRegistry::GROUP_GENERAL,
            'key' => $key,
        ]);

        $oldPath = $existing->value;
        $extension = $file->getClientOriginalExtension() ?: $file->extension() ?: 'bin';
        $path = $file->storeAs(
            'system-assets/'.SystemSettingsRegistry::GROUP_GENERAL,
            sprintf('%s-%s.%s', $key, Str::uuid7(), strtolower($extension)),
            'public',
        );

        if (is_string($oldPath) && $oldPath !== '' && Storage::disk('public')->exists($oldPath)) {
            Storage::disk('public')->delete($oldPath);
        }

        if (! $existing->exists) {
            $existing->id = (string) Str::uuid7();
            $existing->fill([
                'type' => $definition['type'],
                'label_en' => $definition['label_en'] ?? null,
                'label_am' => $definition['label_am'] ?? null,
                'description_en' => $definition['description_en'] ?? null,
                'description_am' => $definition['description_am'] ?? null,
                'is_public' => (bool) ($definition['is_public'] ?? false),
                'is_encrypted' => (bool) ($definition['is_encrypted'] ?? false),
                'is_system' => true,
                'is_required' => (bool) ($definition['is_required'] ?? false),
                'sort_order' => (int) ($definition['sort_order'] ?? 0),
                'options' => $definition['options'] ?? null,
                'validation_rules' => $definition['validation_rules'] ?? null,
                'default_value' => null,
            ]);
        }

        $existing->value = $path;
        $existing->updated_by = $actor->id;
        $existing->save();

        $this->settingsService->clearCache();

        $this->writeAuditLogAction->execute(
            AuditEventType::SettingAssetUploaded,
            $actor,
            $existing,
            null,
            ['configured' => ! empty($oldPath)],
            ['configured' => true, 'key' => $key],
        );

        return $existing;
    }
}

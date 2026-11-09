<?php

declare(strict_types=1);

namespace App\Actions\SystemSettings;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\SystemSettings\SystemSettingsRegistry;
use App\Services\SystemSettings\SystemSettingsService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class UpdateSystemSettingsGroupAction
{
    public function __construct(
        private readonly WriteAuditLogAction $writeAuditLogAction,
        private readonly SystemSettingsService $settingsService,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $clear  Secret keys whose saved value is removed. A blank
     *                               secret in $data keeps the saved one, so removing
     *                               it has to be asked for by name.
     */
    public function execute(string $group, array $data, User $actor, array $clear = []): void
    {
        $definitions = SystemSettingsRegistry::group($group);
        if ($definitions === []) {
            return;
        }

        $oldSnapshot = [];
        $newSnapshot = [];

        DB::transaction(function () use ($group, $data, $clear, $definitions, $actor, &$oldSnapshot, &$newSnapshot): void {
            foreach ($definitions as $key => $def) {
                $isEncrypted = (bool) ($def['is_encrypted'] ?? false);
                $isSecret = $isEncrypted || $def['type'] === 'password';
                $removing = $isSecret && in_array($key, $clear, true);

                if (! $removing && ! array_key_exists($key, $data)) {
                    continue;
                }

                $incoming = $removing ? null : $data[$key];

                /** @var SystemSetting $row */
                $row = SystemSetting::query()->firstOrNew([
                    'group' => $group,
                    'key' => $key,
                ]);

                $metaFields = [
                    'type' => $def['type'],
                    'label_en' => $def['label_en'],
                    'label_am' => $def['label_am'],
                    'description_en' => $def['description_en'] ?? null,
                    'description_am' => $def['description_am'] ?? null,
                    'is_public' => (bool) ($def['is_public'] ?? false),
                    'is_encrypted' => $isEncrypted,
                    'is_system' => true,
                    'is_required' => (bool) ($def['is_required'] ?? false),
                    'sort_order' => (int) ($def['sort_order'] ?? 0),
                    'options' => $def['options'] ?? null,
                    'validation_rules' => $def['validation_rules'] ?? null,
                    'default_value' => $this->normalizeDefaultValue($def['default'] ?? null),
                ];

                $row->fill($metaFields);

                // For encrypted fields: blank submission = keep old value
                if (! $removing && $isEncrypted && ($incoming === null || $incoming === '')) {
                    $oldSnapshot[$key] = $row->value ? 'configured' : 'not_configured';
                    $newSnapshot[$key] = $row->value ? 'configured' : 'not_configured';

                    continue;
                }

                $storedValue = $this->normalizeForStorage($def['type'], $incoming);

                if ($isEncrypted && $storedValue !== null && $storedValue !== '') {
                    $storedValue = Crypt::encryptString($storedValue);
                }

                if ($isSecret) {
                    $oldSnapshot[$key] = $row->value ? 'configured' : 'not_configured';
                    $newSnapshot[$key] = ($storedValue !== null && $storedValue !== '') ? 'configured' : 'not_configured';
                } else {
                    $oldSnapshot[$key] = $row->value;
                    $newSnapshot[$key] = $storedValue;
                }

                $row->value = $storedValue;
                $row->updated_by = $actor->id;
                $row->save();
            }
        });

        $this->settingsService->clearCache();

        $this->writeAuditLogAction->execute(
            AuditEventType::SettingUpdated,
            $actor,
            null,
            null,
            oldValues: ['group' => $group, 'values' => $oldSnapshot],
            newValues: ['group' => $group, 'values' => $newSnapshot],
        );
    }

    private function normalizeForStorage(string $type, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false',
            'integer' => (string) (int) $value,
            'json', 'multiselect' => json_encode($value),
            default => is_string($value) ? $value : (string) $value,
        };
    }

    private function normalizeDefaultValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return is_array($value) ? json_encode($value) : (string) $value;
    }
}

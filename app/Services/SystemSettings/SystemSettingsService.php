<?php

declare(strict_types=1);

namespace App\Services\SystemSettings;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Cache;

class SystemSettingsService
{
    private const CACHE_TTL_SECONDS = 3600;

    private const CACHE_KEY_ALL = 'system_settings_all';

    private const CACHE_KEY_PUBLIC = 'system_settings_public';

    public function get(string $group, string $key, mixed $default = null): mixed
    {
        $all = $this->all();

        $value = $all[$group][$key] ?? null;

        if ($value === null) {
            $def = SystemSettingsRegistry::definitions()[$group][$key] ?? null;

            return $def['default'] ?? $default;
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    public function getGroup(string $group): array
    {
        $all = $this->all();

        return $all[$group] ?? [];
    }

    /**
     * Returns dot-notated public settings safe for frontend exposure.
     *
     * @return array<string, mixed>
     */
    public function getPublicSettings(): array
    {
        return Cache::remember(
            self::CACHE_KEY_PUBLIC,
            self::CACHE_TTL_SECONDS,
            fn (): array => $this->publicSettingsService->shareableSettings(),
        );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        return Cache::remember(self::CACHE_KEY_ALL, self::CACHE_TTL_SECONDS, function (): array {
            $result = [];
            $definitions = SystemSettingsRegistry::definitions();
            $rows = SystemSetting::query()->get()->keyBy(fn (SystemSetting $s) => $s->group.'.'.$s->key);

            foreach ($definitions as $group => $fields) {
                $result[$group] = [];
                foreach ($fields as $key => $def) {
                    /** @var SystemSetting|null $row */
                    $row = $rows->get($group.'.'.$key);
                    if ($row && $row->value !== null && $row->value !== '') {
                        $result[$group][$key] = $row->typedValue();
                    } else {
                        $result[$group][$key] = $def['default'] ?? null;
                    }
                }
            }

            return $result;
        });
    }

    /**
     * Returns admin-facing payload for a group: actual values where safe,
     * `null` for secret fields plus a "configured" flag.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getGroupForAdmin(string $group): array
    {
        $definitions = SystemSettingsRegistry::group($group);
        $rows = SystemSetting::query()
            ->where('group', $group)
            ->get()
            ->keyBy('key');

        $fields = [];
        foreach ($definitions as $key => $def) {
            /** @var SystemSetting|null $row */
            $row = $rows->get($key);
            $isEncrypted = (bool) ($def['is_encrypted'] ?? false);
            $isSecret = $isEncrypted || $def['type'] === 'password';

            $rawValue = null;
            $configured = false;
            if ($row) {
                $configured = $row->value !== null && $row->value !== '';
                if (! $isSecret) {
                    $rawValue = $row->typedValue();
                }
            } else {
                $rawValue = $isSecret ? null : ($def['default'] ?? null);
            }

            $fields[] = [
                'key' => $key,
                'group' => $group,
                'type' => $def['type'],
                'value' => $rawValue,
                'is_encrypted' => $isEncrypted,
                'is_public' => (bool) ($def['is_public'] ?? false),
                'configured' => $configured,
                'options' => $def['options'] ?? null,
                'validation_rules' => $def['validation_rules'] ?? [],
                'description_en' => $def['description_en'] ?? null,
                'description_am' => $def['description_am'] ?? null,
                'label_en' => $def['label_en'],
                'label_am' => $def['label_am'],
                'sort_order' => $def['sort_order'] ?? 0,
                'default' => $def['default'] ?? null,
                'is_required' => (bool) ($def['is_required'] ?? false),
                'asset_url' => in_array($def['type'], ['file', 'image'], true) && is_string($row?->typedValue())
                    ? '/storage/'.(string) $row?->typedValue()
                    : null,
            ];
        }

        usort($fields, fn ($a, $b) => $a['sort_order'] <=> $b['sort_order']);

        return $fields;
    }

    public function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY_ALL);
        Cache::forget(self::CACHE_KEY_PUBLIC);
    }

    public function __construct(
        private readonly PublicSettingsService $publicSettingsService,
    ) {}

}

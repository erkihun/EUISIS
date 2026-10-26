<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CoreSetting;
use App\Models\User;
use App\Settings\CoreSettingsRegistry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Reads and changes the Core settings. A value is the stored row, else the
 * registry default; reads come from one cached map, cleared on every change.
 * Secrets (the registry's is_encrypted fields, as in EUISIS) are stored
 * encrypted, never listed by values() and never written to the audit log.
 */
final class CoreSettings
{
    private const CACHE_KEY = 'core.settings.values';

    private const SECRET_MARK = '[set]';

    public function __construct(private readonly AuditWriter $audit) {}

    /** One value by "group.key", typed as the registry says (a secret decrypted). */
    public function get(string $name): mixed
    {
        [$group, $key] = explode('.', $name, 2);
        $definition = CoreSettingsRegistry::definition($group, $key);
        if ($definition === null) {
            throw new \InvalidArgumentException("Unknown Core setting {$name}.");
        }
        $stored = $this->stored();
        if (! array_key_exists($name, $stored)) {
            return $this->cast($definition, $definition['default']);
        }
        $value = $stored[$name];
        if (($definition['is_encrypted'] ?? false) && is_string($value) && $value !== '') {
            try {
                $value = Crypt::decryptString($value);
            } catch (Throwable) {
                return null;
            }
        }

        return $this->cast($definition, $value);
    }

    public function int(string $name): int
    {
        return (int) $this->get($name);
    }

    /** Whether a value is stored for the setting (a secret counts as set without being read). */
    public function configured(string $name): bool
    {
        $value = $this->stored()[$name] ?? null;

        return $value !== null && $value !== '' && $value !== [];
    }

    /** @return array<string, array<string, mixed>> group => key => value; secrets are null */
    public function values(bool $publicOnly = false): array
    {
        $values = [];
        foreach (CoreSettingsRegistry::definitions() as $group => $fields) {
            foreach ($fields as $key => $definition) {
                if (! $publicOnly || $definition['is_public']) {
                    $values[$group][$key] = ($definition['is_encrypted'] ?? false) ? null : $this->get("{$group}.{$key}");
                }
            }
        }

        return $values;
    }

    /**
     * Validates and stores the given values of one group, audited with the old
     * and new values. Unknown keys are refused. A blank secret keeps the saved
     * one; a key in $clear removes it. Images and files are set with setAsset().
     *
     * @param  array<string, mixed>  $input
     * @param  list<string>  $clear  secrets to remove
     * @return array<string, mixed> the group's values after the change
     */
    public function update(string $group, array $input, User $actor, array $clear = []): array
    {
        $fields = CoreSettingsRegistry::definitions()[$group] ?? null;
        if ($fields === null) {
            throw ValidationException::withMessages(['group' => 'Unknown settings group.']);
        }
        $unknown = array_diff(array_keys($input), array_keys($fields));
        if ($unknown !== []) {
            throw ValidationException::withMessages([reset($unknown) => 'This setting does not exist.']);
        }
        foreach ($input as $key => $value) {
            if (($fields[$key]['is_encrypted'] ?? false) && ($value === null || $value === '')) {
                unset($input[$key]);
            }
        }

        $rules = [];
        foreach (array_keys($input) as $key) {
            $rules[$key] = $fields[$key]['rules'];
            if ($fields[$key]['type'] === 'multiselect') {
                $rules[$key.'.*'] = ['string', 'distinct', Rule::in($fields[$key]['options'] ?? [])];
            }
        }
        $valid = Validator::make($input, $rules)->validate();

        DB::transaction(function () use ($group, $fields, $valid, $clear, $actor): void {
            $old = [];
            $new = [];
            foreach ($valid as $key => $value) {
                $secret = $fields[$key]['is_encrypted'] ?? false;
                $value = $this->cast($fields[$key], $value);
                $before = $this->get("{$group}.{$key}");
                if ($before === $value) {
                    continue;
                }
                CoreSetting::query()->updateOrCreate(['group' => $group, 'key' => $key],
                    ['value' => $secret ? Crypt::encryptString((string) $value) : $value, 'updated_by' => $actor->getKey()]);
                $old[$key] = $secret ? ($before === null ? null : self::SECRET_MARK) : $before;
                $new[$key] = $secret ? self::SECRET_MARK : $value;
            }
            foreach (array_intersect($clear, array_keys($fields)) as $key) {
                if (($fields[$key]['is_encrypted'] ?? false) && $this->configured("{$group}.{$key}")) {
                    CoreSetting::query()->where('group', $group)->where('key', $key)->delete();
                    $old[$key] = self::SECRET_MARK;
                    $new[$key] = null;
                }
            }
            if ($new !== []) {
                $this->audit->writeFor('settings.updated', $actor, 'CoreSettings', $group, null, $old, $new);
            }
            Cache::forget(self::CACHE_KEY);
        });

        return $this->values()[$group];
    }

    /** Sets or removes an image or file setting (a path on the public disk), audited. */
    public function setAsset(string $group, string $key, ?string $path, User $actor): void
    {
        $definition = CoreSettingsRegistry::definition($group, $key);
        if ($definition === null || ! in_array($definition['type'], ['image', 'file'], true)) {
            throw new \InvalidArgumentException("{$group}.{$key} is not an image or file setting.");
        }
        $before = $this->get("{$group}.{$key}");
        if ($path === null) {
            CoreSetting::query()->where('group', $group)->where('key', $key)->delete();
        } else {
            CoreSetting::query()->updateOrCreate(['group' => $group, 'key' => $key], ['value' => $path, 'updated_by' => $actor->getKey()]);
        }
        $this->audit->writeFor('settings.updated', $actor, 'CoreSettings', $group, null, [$key => $before], [$key => $path]);
        Cache::forget(self::CACHE_KEY);
    }

    /** @return array<string, mixed> "group.key" => stored value */
    /** Forgets the cached values so the next read comes from the table (System Settings > Clear cache). */
    public function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    private function stored(): array
    {
        return Cache::remember(self::CACHE_KEY, 3600, fn (): array => CoreSetting::query()
            ->get(['group', 'key', 'value'])
            ->mapWithKeys(fn (CoreSetting $row): array => ["{$row->group}.{$row->key}" => $row->value])
            ->all());
    }

    /** @param array<string, mixed> $definition */
    private function cast(array $definition, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($definition['type']) {
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOL),
            'integer' => (int) $value,
            'color' => strtoupper((string) $value),
            'multiselect', 'json' => array_values(array_filter((array) (is_string($value) ? (json_decode($value, true) ?? []) : $value), 'is_string')),
            default => (string) $value,
        };
    }
}

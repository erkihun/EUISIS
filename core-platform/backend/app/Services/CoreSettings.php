<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CoreSetting;
use App\Models\User;
use App\Settings\CoreSettingsRegistry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Reads and changes the Core settings. A value is the stored row, else the
 * registry default; reads come from one cached map, cleared on every change.
 */
final class CoreSettings
{
    private const CACHE_KEY = 'core.settings.values';

    public function __construct(private readonly AuditWriter $audit) {}

    /** One value by "group.key", typed as the registry says. */
    public function get(string $name): mixed
    {
        [$group, $key] = explode('.', $name, 2);
        $definition = CoreSettingsRegistry::definition($group, $key);
        if ($definition === null) {
            throw new \InvalidArgumentException("Unknown Core setting {$name}.");
        }
        $stored = $this->stored();

        return $this->cast($definition, array_key_exists($name, $stored) ? $stored[$name] : $definition['default']);
    }

    public function int(string $name): int
    {
        return (int) $this->get($name);
    }

    /** @return array<string, array<string, mixed>> group => key => value */
    public function values(bool $publicOnly = false): array
    {
        $values = [];
        foreach (CoreSettingsRegistry::definitions() as $group => $fields) {
            foreach ($fields as $key => $definition) {
                if (! $publicOnly || $definition['is_public']) {
                    $values[$group][$key] = $this->get("{$group}.{$key}");
                }
            }
        }

        return $values;
    }

    /**
     * Validates and stores the given values of one group, audited with the
     * old and new values (settings hold no secrets). Unknown keys are refused.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed> the group's values after the change
     */
    public function update(string $group, array $input, User $actor): array
    {
        $fields = CoreSettingsRegistry::definitions()[$group] ?? null;
        if ($fields === null) {
            throw ValidationException::withMessages(['group' => 'Unknown settings group.']);
        }
        $unknown = array_diff(array_keys($input), array_keys($fields));
        if ($unknown !== []) {
            throw ValidationException::withMessages([reset($unknown) => 'This setting does not exist.']);
        }

        $rules = [];
        foreach (array_keys($input) as $key) {
            $rules[$key] = $fields[$key]['rules'];
        }
        $valid = Validator::make($input, $rules)->validate();

        DB::transaction(function () use ($group, $fields, $valid, $actor): void {
            $old = [];
            $new = [];
            foreach ($valid as $key => $value) {
                $value = $this->cast($fields[$key], $value);
                $before = $this->get("{$group}.{$key}");
                if ($before === $value) {
                    continue;
                }
                CoreSetting::query()->updateOrCreate(['group' => $group, 'key' => $key], ['value' => $value, 'updated_by' => $actor->getKey()]);
                $old[$key] = $before;
                $new[$key] = $value;
            }
            if ($new !== []) {
                $this->audit->writeFor('settings.updated', $actor, 'CoreSettings', $group, null, $old, $new);
            }
            Cache::forget(self::CACHE_KEY);
        });

        return $this->values()[$group];
    }

    /** @return array<string, mixed> "group.key" => stored value */
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
            default => (string) $value,
        };
    }
}

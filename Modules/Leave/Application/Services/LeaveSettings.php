<?php

declare(strict_types=1);

namespace Modules\Leave\Application\Services;

use Illuminate\Support\Facades\Schema;
use Modules\Leave\Domain\Models\LeaveSetting;

/**
 * The switches of the leave module, as the settings page sets them. Every
 * switch has a default that is the behaviour the owner asked for, so a fresh
 * installation works with no row in the table.
 *
 *   hr_confirmation_required    after the supervisor approves, an HR officer confirms (the balance is spent then);
 *                               off: the supervisor's approval is final
 *   min_notice_days             how many days ahead a leave must be asked for (types that may start in the past are exempt)
 *   schedule_enabled            employees state their leave schedule for the budget year
 *   schedule_requires_approval  a planned period waits for approval; off: it counts as approved when it is added
 *   schedule_auto_count         an approved planned period is counted as leave by the system on its first day
 */
class LeaveSettings
{
    public const DEFAULTS = [
        'hr_confirmation_required' => true,
        'min_notice_days' => 0,
        'schedule_enabled' => true,
        'schedule_requires_approval' => true,
        'schedule_auto_count' => true,
    ];

    /** @var array<string, mixed>|null */
    private ?array $loaded = null;

    /** @return array<string, mixed> */
    public function all(): array
    {
        if ($this->loaded === null) {
            $stored = [];
            // The table arrives with a migration: before it has run the defaults apply.
            if (Schema::hasTable('leave_settings')) {
                foreach (LeaveSetting::query()->get() as $row) {
                    $stored[$row->key] = $row->value['v'] ?? null;
                }
            }
            $this->loaded = [];
            foreach (self::DEFAULTS as $key => $default) {
                $value = $stored[$key] ?? $default;
                $this->loaded[$key] = is_bool($default) ? (bool) $value : max(0, (int) $value);
            }
        }

        return $this->loaded;
    }

    public function bool(string $key): bool
    {
        return (bool) ($this->all()[$key] ?? false);
    }

    public function int(string $key): int
    {
        return (int) ($this->all()[$key] ?? 0);
    }

    /**
     * Save the switches named in $values; unknown keys are ignored.
     *
     * @param  array<string, mixed>  $values
     * @return array{before: array<string, mixed>, after: array<string, mixed>}
     */
    public function update(array $values, ?int $actorUserId): array
    {
        $before = $this->all();
        foreach (self::DEFAULTS as $key => $default) {
            if (! array_key_exists($key, $values)) {
                continue;
            }
            $value = is_bool($default) ? (bool) $values[$key] : max(0, (int) $values[$key]);
            LeaveSetting::query()->updateOrCreate(['key' => $key], ['value' => ['v' => $value], 'updated_by' => $actorUserId]);
        }
        $this->loaded = null;

        return ['before' => $before, 'after' => $this->all()];
    }
}

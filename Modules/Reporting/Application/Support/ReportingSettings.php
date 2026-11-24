<?php

declare(strict_types=1);

namespace Modules\Reporting\Application\Support;

use Modules\Reporting\Infrastructure\Persistence\Models\Setting;

/**
 * The module's own configuration: defaults in Config/reporting.php, values
 * changed on the settings page in reporting_settings.
 *
 * `maintenance` is the switch that pauses the module: every write route,
 * scheduled task and queued job checks it. Data stays readable; queued work
 * stays queued and resumes when the switch is turned off.
 */
final class ReportingSettings
{
    public const KEYS = ['maintenance', 'max_upload_kb', 'reminder_rules', 'document_font', 'email_notifications'];

    /** @var array<string, mixed>|null */
    private ?array $stored = null;

    public function get(string $key): mixed
    {
        $this->stored ??= Setting::query()->pluck('value', 'key')->all();

        return $this->stored[$key] ?? config("reporting.{$key}");
    }

    public function isPaused(): bool
    {
        return (bool) $this->get('maintenance');
    }

    public function assertWritable(): void
    {
        if ($this->isPaused()) {
            throw new ReportingPaused;
        }
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return array_combine(self::KEYS, array_map($this->get(...), self::KEYS));
    }

    public function put(string $key, mixed $value, int $userId): void
    {
        Setting::query()->updateOrCreate(['key' => $key], ['value' => $value, 'updated_by' => $userId]);
        $this->stored = null;
    }
}

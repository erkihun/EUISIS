<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Application\Services;

use Modules\ServiceMonitoring\Domain\Models\DriverOccupation;
use Modules\ServiceMonitoring\Domain\Models\MonitoringSetting;

/**
 * The module's single settings row (declaration text, stamped-copy rule) and
 * the driver occupation list. The row is created with the defaults on first
 * read, so a fresh install works without a seeder.
 */
class ServiceMonitoringSettings
{
    private ?MonitoringSetting $row = null;

    public function row(): MonitoringSetting
    {
        return $this->row ??= MonitoringSetting::query()->oldest()->first()
            ?? MonitoringSetting::query()->create([
                'require_stamped_copy' => false,
                'declaration_text_en' => (string) __('service-monitoring.declaration_default', [], 'en'),
                'declaration_text_am' => (string) __('service-monitoring.declaration_default', [], 'am'),
            ]);
    }

    public function forget(): void
    {
        $this->row = null;
    }

    public function requiresStampedCopy(): bool
    {
        return (bool) $this->row()->require_stamped_copy;
    }

    public function declaration(?string $locale = null): string
    {
        $row = $this->row();
        $text = ($locale ?? app()->getLocale()) === 'am' ? $row->declaration_text_am : $row->declaration_text_en;

        return trim((string) $text) !== '' ? (string) $text : (string) __('service-monitoring.declaration_default', [], $locale);
    }

    /** @return list<string> */
    public function driverOccupationIds(): array
    {
        return DriverOccupation::query()->pluck('occupation_id')->map(fn ($id): string => (string) $id)->all();
    }
}

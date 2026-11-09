<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Domain\Enums;

/** A queued export. */
enum ExportStatus: string
{
    case Queued = 'queued';
    case Ready = 'ready';
    case Failed = 'failed';

    public function label(?string $locale = null): string
    {
        return (string) __('service-monitoring.enums.export_status.'.$this->value, [], $locale);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

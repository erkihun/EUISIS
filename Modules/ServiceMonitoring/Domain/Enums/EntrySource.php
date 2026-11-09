<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Domain\Enums;

/** Where a Section I row came from: typed in, or accepted from a final disciplinary decision. */
enum EntrySource: string
{
    case Manual = 'manual';
    case Discipline = 'discipline';

    public function label(?string $locale = null): string
    {
        return (string) __('service-monitoring.enums.entry_source.'.$this->value, [], $locale);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

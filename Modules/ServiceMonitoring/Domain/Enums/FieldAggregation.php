<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Domain\Enums;

/** How a figure combines across quarters (D4): flow fields are summed, stock fields take the latest quarter. */
enum FieldAggregation: string
{
    case Flow = 'flow';
    case Stock = 'stock';

    public function label(?string $locale = null): string
    {
        return (string) __('service-monitoring.enums.field_aggregation.'.$this->value, [], $locale);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Domain\Enums;

/** A support action item. */
enum ActionItemStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case Done = 'done';
    case Cancelled = 'cancelled';

    public function label(?string $locale = null): string
    {
        return (string) __('service-monitoring.enums.action_item_status.'.$this->value, [], $locale);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function isClosed(): bool
    {
        return $this === self::Done || $this === self::Cancelled;
    }
}

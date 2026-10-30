<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Domain\Enums;

/** A clarification thread: open → answered → closed (D22). */
enum ClarificationStatus: string
{
    case Open = 'open';
    case Answered = 'answered';
    case Closed = 'closed';

    public function label(?string $locale = null): string
    {
        return (string) __('service-monitoring.enums.clarification_status.'.$this->value, [], $locale);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

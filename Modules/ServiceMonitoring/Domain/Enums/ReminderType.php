<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Domain\Enums;

/** Reminder kinds (D23); each is sent at most once per participant, subject and day. */
enum ReminderType: string
{
    case PeriodOpened = 'period_opened';
    case DueInThreeDays = 'due_in_3_days';
    case DueToday = 'due_today';
    case MissingAfterDue = 'missing_after_due';
    case Returned = 'returned';
    case ActionItemOverdue = 'action_item_overdue';

    public function label(?string $locale = null): string
    {
        return (string) __('service-monitoring.enums.reminder_type.'.$this->value, [], $locale);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

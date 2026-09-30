<?php

declare(strict_types=1);

namespace App\Enums\Grievance;

/**
 * Channels a letter can be dispatched through.
 */
enum GrievanceDispatchChannel: string
{
    case InApp = 'in_app';
    case Email = 'email';
    case SmsNotice = 'sms_notice';
    case Printed = 'printed';
    case OfficialRegistry = 'official_registry';
    case Other = 'other';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

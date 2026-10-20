<?php

declare(strict_types=1);

namespace App\Enums\Communication;

/** What a report is about. */
enum ReportSubject: string
{
    case Message = 'MESSAGE';
    case Conversation = 'CONVERSATION';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

<?php

declare(strict_types=1);

namespace App\Enums\Communication;

/** The kind of conversation stream. A channel is a CHANNEL stream wrapped by a `channels` row. */
enum ConversationType: string
{
    case Direct = 'DIRECT';
    case Group = 'GROUP';
    case Channel = 'CHANNEL';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

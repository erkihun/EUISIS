<?php

declare(strict_types=1);

namespace Modules\Communication\Domain\Enums;

/** What a break-glass grant covers: one conversation, or the conversations of one employee. */
enum AccessScopeType: string
{
    case Conversation = 'CONVERSATION';
    case Employee = 'EMPLOYEE';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

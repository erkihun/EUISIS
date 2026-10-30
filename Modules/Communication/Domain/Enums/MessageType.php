<?php

declare(strict_types=1);

namespace Modules\Communication\Domain\Enums;

/** What a message carries. */
enum MessageType: string
{
    case Text = 'TEXT';
    case Image = 'IMAGE';
    case File = 'FILE';
    case System = 'SYSTEM';
    case TaskReference = 'TASK_REFERENCE';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

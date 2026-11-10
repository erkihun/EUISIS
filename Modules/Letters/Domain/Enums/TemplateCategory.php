<?php

declare(strict_types=1);

namespace Modules\Letters\Domain\Enums;

/** The kinds of official letters the templates are grouped by. */
enum TemplateCategory: string
{
    case General = 'general';
    case Notice = 'notice';
    case Request = 'request';
    case Response = 'response';
    case Decision = 'decision';
    case Transmittal = 'transmittal';
    case Invitation = 'invitation';
    case Memo = 'memo';
    case Other = 'other';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}

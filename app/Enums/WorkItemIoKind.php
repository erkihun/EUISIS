<?php

declare(strict_types=1);

namespace App\Enums;

/** The typed rows attached to a process, activity or task (brief §22): never free text. */
enum WorkItemIoKind: string
{
    case Input = 'input';
    case Output = 'output';
    case Beneficiary = 'beneficiary';
    case Dependency = 'dependency';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}

<?php

declare(strict_types=1);

namespace App\Enums;

/** What a request item does to its target entity. */
enum OrganizationalChangeAction: string
{
    case Create = 'create';
    case Update = 'update';
    case Move = 'move';
    case Deactivate = 'deactivate';
    case Abolish = 'abolish';
    case Increase = 'increase';
    case Narrative = 'narrative';
}

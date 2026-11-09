<?php

declare(strict_types=1);

namespace App\Enums;

enum RoleScopeType: string
{
    case Scoped = 'scoped';
    case Global = 'global';
}

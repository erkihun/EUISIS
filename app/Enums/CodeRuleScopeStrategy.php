<?php

declare(strict_types=1);

namespace App\Enums;

enum CodeRuleScopeStrategy: string
{
    case Auto = 'auto';
    case Global = 'global';
    case CustomTokens = 'custom_tokens';
}

<?php

declare(strict_types=1);

namespace App\Security\Passwords;

enum BreachCheckResult: string
{
    case Clean = 'clean';
    case Compromised = 'compromised';
    /** The service could not answer; the policy decides (fail open / closed). */
    case Unavailable = 'unavailable';
}

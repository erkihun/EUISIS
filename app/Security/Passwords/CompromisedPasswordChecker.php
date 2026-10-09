<?php

declare(strict_types=1);

namespace App\Security\Passwords;

/**
 * Whether a password appears in known breach corpora.
 *
 * Implementations must never send the password or its complete hash
 * anywhere, and must never log either.
 */
interface CompromisedPasswordChecker
{
    public function check(#[\SensitiveParameter] string $password): BreachCheckResult;
}

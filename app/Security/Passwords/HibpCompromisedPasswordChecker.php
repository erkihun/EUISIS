<?php

declare(strict_types=1);

namespace App\Security\Passwords;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Have I Been Pwned "Pwned Passwords" range API (k-anonymity).
 *
 * Only the first 5 hex characters of the SHA-1 digest are sent; the service
 * returns every suffix sharing that prefix and the match happens here. The
 * `Add-Padding` header pads responses so their size reveals nothing. Padding
 * rows carry a count of 0 and are ignored.
 *
 * Nothing about the password, the prefix or the response is logged.
 */
final class HibpCompromisedPasswordChecker implements CompromisedPasswordChecker
{
    public function check(#[\SensitiveParameter] string $password): BreachCheckResult
    {
        $digest = strtoupper(sha1($password));
        $prefix = substr($digest, 0, 5);
        $suffix = substr($digest, 5);

        try {
            $response = Http::timeout(max(1, (int) config('security.passwords.breach_check.timeout_seconds', 3)))
                ->withHeaders(['Add-Padding' => 'true', 'User-Agent' => 'EUISIS-password-policy'])
                ->get(rtrim((string) config('security.passwords.breach_check.endpoint'), '/').'/'.$prefix);
        } catch (Throwable) {
            return BreachCheckResult::Unavailable;
        }

        if (! $response->successful()) {
            return BreachCheckResult::Unavailable;
        }

        foreach (preg_split('/\r\n|\n|\r/', (string) $response->body()) ?: [] as $line) {
            [$candidate, $count] = array_pad(explode(':', trim($line)), 2, '0');
            if (hash_equals($suffix, strtoupper($candidate)) && (int) $count > 0) {
                return BreachCheckResult::Compromised;
            }
        }

        return BreachCheckResult::Clean;
    }
}

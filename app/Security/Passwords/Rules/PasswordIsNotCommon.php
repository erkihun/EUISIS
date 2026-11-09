<?php

declare(strict_types=1);

namespace App\Security\Passwords\Rules;

use App\Security\Passwords\PersonalInformation;
use App\Services\Security\DefaultPasswordPolicyService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Rejects predictable passwords, context-specifically (NIST SP 800-63B-4
 * §3.1.1.2):
 *
 *  - an entry of the common-password blocklist, alone or decorated with
 *    digits/symbols ("Password123456789!" is "password");
 *  - a password built only from predictable service words and digits
 *    ("EUISIS-Admin-2026!!", "AddisAbaba12345!");
 *  - repetitive or sequential characters ("aaaaaaaaaaaaaaa",
 *    "123456789012345", "qwertyuiopasdfg");
 *  - the legacy shared default password, if one is still configured.
 */
final class PasswordIsNotCommon implements ValidationRule
{
    /** @var array<string, true>|null */
    private static ?array $blocklist = null;

    private const KEYBOARD_ROWS = ['qwertyuiopasdfghjklzxcvbnm', 'qazwsxedcrfvtgbyhnujmikolp', 'zaq1xsw2cde3vfr4bgt5nhy6mju7', '1qaz2wsx3edc4rfv5tgb6yhn7ujm8ik9ol0p'];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        if (app(DefaultPasswordPolicyService::class)->matches($value)) {
            $fail(__('auth.password_cannot_be_default'));

            return;
        }

        $normalized = PersonalInformation::normalize($value);
        $letters = (string) preg_replace('/[^\p{L}]+/u', '', $normalized);

        if (isset(self::blocklist()[$normalized])
            || ($letters !== '' && isset(self::blocklist()[$letters]))
            || ($letters !== '' && $this->builtFromContextTerms($letters))
            || $this->isRepetitiveOrSequential($normalized)) {
            $fail(__('password-policy.common'));
        }
    }

    /** @internal Reset the cached list (tests). */
    public static function flush(): void
    {
        self::$blocklist = null;
    }

    /** @return array<string, true> */
    private static function blocklist(): array
    {
        if (self::$blocklist !== null) {
            return self::$blocklist;
        }

        $entries = [];
        $path = (string) config('security.passwords.blocklist_path');
        if ($path !== '' && is_readable($path)) {
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $line = trim($line);
                if ($line !== '' && ! str_starts_with($line, '#')) {
                    $entries[PersonalInformation::normalize($line)] = true;
                }
            }
        }
        foreach ((array) config('security.passwords.context_terms', []) as $term) {
            $entries[PersonalInformation::normalize((string) $term)] = true;
        }
        unset($entries['']);

        return self::$blocklist = $entries;
    }

    /** True when the letters are nothing but predictable service words, e.g. "euisisadminadmin". */
    private function builtFromContextTerms(string $letters): bool
    {
        $terms = array_values(array_filter(array_map(
            static fn ($term): string => PersonalInformation::normalize((string) $term),
            (array) config('security.passwords.context_terms', []),
        )));
        $length = mb_strlen($letters);
        $reachable = array_fill(0, $length + 1, false);
        $reachable[0] = true;

        for ($i = 0; $i < $length; $i++) {
            if (! $reachable[$i]) {
                continue;
            }
            foreach ($terms as $term) {
                $termLength = mb_strlen($term);
                if ($termLength > 0 && mb_substr($letters, $i, $termLength) === $term) {
                    $reachable[$i + $termLength] = true;
                }
            }
        }

        return $reachable[$length];
    }

    private function isRepetitiveOrSequential(string $normalized): bool
    {
        $chars = mb_str_split($normalized);
        $count = count($chars);
        if ($count < 4) {
            return false;
        }

        // Very few distinct characters: "aaaa...", "abababab...".
        if (count(array_unique($chars)) <= 3) {
            return true;
        }

        // A repeated short unit: "abcdabcdabcdabcd", "2026202620262026".
        for ($unit = 1; $unit <= 6 && $count >= $unit * 3; $unit++) {
            $piece = mb_substr($normalized, 0, $unit);
            if (mb_substr(str_repeat($piece, intdiv($count, $unit) + 1), 0, $count) === $normalized) {
                return true;
            }
        }

        // Mostly runs of +1/-1 steps: "123456789012345", "abcdefghijklmno".
        $sequential = 0;
        for ($i = 1; $i < $count; $i++) {
            $step = mb_ord($chars[$i]) - mb_ord($chars[$i - 1]);
            if (abs($step) === 1 || ($chars[$i - 1] === '9' && $chars[$i] === '0') || ($chars[$i - 1] === '0' && $chars[$i] === '9')) {
                $sequential++;
            }
        }
        if ($sequential / ($count - 1) >= 0.8) {
            return true;
        }

        // A walk along a keyboard row.
        foreach (self::KEYBOARD_ROWS as $row) {
            $rows = str_repeat($row, 3);
            if (str_contains($rows, $normalized) || str_contains(strrev($rows), $normalized)) {
                return true;
            }
        }

        return false;
    }
}

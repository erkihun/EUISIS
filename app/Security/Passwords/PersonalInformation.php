<?php

declare(strict_types=1);

namespace App\Security\Passwords;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * The account-specific values a password must not contain, already
 * normalized for comparison. Only comparison COPIES are normalized; the
 * submitted password itself is never altered.
 *
 * Categories (each has its own message, and the matched value is never
 * echoed back):
 *   name             each meaningful name token (English and Amharic)
 *   username         the whole username and its meaningful parts
 *   email            the whole local part and its meaningful parts
 *   employee_number  the whole identifier (AAC-48392017 -> aac48392017)
 *   phone            the 9-digit national number (09.., +2519.., 2519..)
 *
 * "Meaningful" = at least `security.passwords.personal_token_min_length`
 * characters (default 4), or 2 for Ethiopic tokens, whose syllable characters
 * each carry a consonant and a vowel. Short fragments are skipped to avoid
 * false positives (a name "Ali" must not reject "Salient Harbor ...").
 */
final class PersonalInformation
{
    /** @param array<string, list<string>> $tokens category => normalized tokens */
    private function __construct(private readonly array $tokens) {}

    /**
     * @param  array<string, mixed>  $identity  values from a form for an account
     *                                          that does not exist yet: name, email,
     *                                          username, phone_number, employee_number
     */
    public static function for(?Model $account, array $identity = []): self
    {
        $values = ['name' => [], 'username' => [], 'email' => [], 'employee_number' => [], 'phone' => []];

        $add = static function (string $category, mixed $value) use (&$values): void {
            if (is_string($value) && trim($value) !== '') {
                $values[$category][] = $value;
            }
        };

        if ($account !== null) {
            $add('name', self::attribute($account, 'name'));
            $add('username', self::attribute($account, 'username'));
            $add('email', self::attribute($account, 'email'));
            $add('phone', self::attribute($account, 'phone_number'));

            $employee = $account instanceof User ? self::employeeOf($account) : null;
            if ($employee !== null) {
                foreach (['first_name', 'middle_name', 'last_name', 'full_name', 'name_en'] as $field) {
                    $add('name', $employee->getAttribute($field));
                }
                $add('employee_number', $employee->getAttribute('employee_number'));
                $add('email', $employee->getAttribute('email'));
                $add('phone', $employee->getAttribute('phone'));
            }
        }

        foreach (['name', 'first_name', 'middle_name', 'last_name', 'full_name', 'name_en', 'user_name'] as $field) {
            $add('name', $identity[$field] ?? null);
        }
        $add('username', $identity['username'] ?? null);
        $add('email', $identity['email'] ?? $identity['user_email'] ?? null);
        $add('employee_number', $identity['employee_number'] ?? null);
        $add('phone', $identity['phone_number'] ?? $identity['phone'] ?? null);

        return new self([
            'name' => self::nameTokens($values['name']),
            'username' => self::identifierTokens($values['username'], '/[._\-+@\s]+/u'),
            'email' => self::identifierTokens(array_map(static fn (string $email): string => explode('@', $email)[0], $values['email']), '/[._\-+\s]+/u'),
            'employee_number' => self::wholeTokens($values['employee_number'], 4),
            'phone' => self::phoneTokens($values['phone']),
        ]);
    }

    /** Lower-case, letters and digits only (any script). */
    public static function normalize(string $value): string
    {
        if (class_exists(\Normalizer::class)) {
            $value = \Normalizer::normalize($value, \Normalizer::FORM_KC) ?: $value;
        }

        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($value, 'UTF-8'));
    }

    /** The first category the password contains, or null. */
    public function violation(string $password): ?string
    {
        $candidate = self::normalize($password);
        if ($candidate === '') {
            return null;
        }

        foreach ($this->tokens as $category => $tokens) {
            foreach ($tokens as $token) {
                if ($token !== '' && str_contains($candidate, $token)) {
                    return $category;
                }
            }
        }

        return null;
    }

    /** @return array<string, list<string>> for the (local, advisory) client checklist only */
    public function tokens(): array
    {
        return $this->tokens;
    }

    /** @param list<string> $names */
    private static function nameTokens(array $names): array
    {
        $tokens = [];
        foreach ($names as $name) {
            $parts = preg_split('/[\s,.\-_\'’]+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            foreach ($parts as $part) {
                $tokens[] = self::normalize($part);
            }
            // Short names together ("Li Wu") are still a meaningful token.
            $tokens[] = self::normalize($name);
        }

        return self::meaningful($tokens);
    }

    /** @param list<string> $values */
    private static function identifierTokens(array $values, string $separator): array
    {
        $tokens = [];
        foreach ($values as $value) {
            $tokens[] = self::normalize($value);
            foreach (preg_split($separator, $value, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
                $tokens[] = self::normalize($part);
            }
        }

        return self::meaningful($tokens);
    }

    /** Whole identifiers only: unrelated partial number runs are not rejected. */
    private static function wholeTokens(array $values, int $minimum): array
    {
        return array_values(array_unique(array_filter(
            array_map(self::normalize(...), $values),
            static fn (string $token): bool => mb_strlen($token) >= $minimum,
        )));
    }

    /** Ethiopian numbers in any format reduce to the 9-digit national number. */
    private static function phoneTokens(array $values): array
    {
        $tokens = [];
        foreach ($values as $value) {
            $digits = (string) preg_replace('/\D+/', '', $value);
            if (strlen($digits) >= 9) {
                $tokens[] = substr($digits, -9);
            }
        }

        return array_values(array_unique($tokens));
    }

    /** @param list<string> $tokens */
    private static function meaningful(array $tokens): array
    {
        $minimum = max(3, (int) config('security.passwords.personal_token_min_length', 4));

        return array_values(array_unique(array_filter($tokens, static function (string $token) use ($minimum): bool {
            $length = mb_strlen($token);

            return preg_match('/\p{Ethiopic}/u', $token) === 1 ? $length >= 2 : $length >= $minimum;
        })));
    }

    private static function attribute(Model $account, string $key): mixed
    {
        try {
            return array_key_exists($key, $account->getAttributes()) ? $account->getAttribute($key) : null;
        } catch (Throwable) {
            return null; // e.g. an encrypted value that cannot be read: skip, never fail the change
        }
    }

    private static function employeeOf(User $user): ?Employee
    {
        try {
            $employee = $user->employee;

            return $employee instanceof Employee ? $employee : null;
        } catch (Throwable) {
            return null;
        }
    }
}

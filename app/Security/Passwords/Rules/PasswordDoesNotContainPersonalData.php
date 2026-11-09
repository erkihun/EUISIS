<?php

declare(strict_types=1);

namespace App\Security\Passwords\Rules;

use App\Security\Passwords\PersonalInformation;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Rejects a password that contains the account holder's name, username,
 * email, employee number or phone number (see PersonalInformation for how
 * "contains" is decided). The message names the category, never the value.
 */
final class PasswordDoesNotContainPersonalData implements ValidationRule
{
    public function __construct(private readonly PersonalInformation $information) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        $category = $this->information->violation($value);
        if ($category !== null) {
            $fail(__('password-policy.contains_'.$category));
        }
    }
}

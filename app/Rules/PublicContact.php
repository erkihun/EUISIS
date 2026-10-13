<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A way to reach a public submitter: an e-mail address or a phone number
 * (digits with an optional leading +, spaces, dashes and parentheses).
 */
final class PublicContact implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $value = is_string($value) ? trim($value) : '';

        if ($value === '') {
            return;
        }

        if (filter_var($value, FILTER_VALIDATE_EMAIL) !== false) {
            return;
        }

        if (preg_match('/^\+?[0-9][0-9 ()\-]{6,19}$/', $value) === 1 && preg_match_all('/[0-9]/', $value) >= 7) {
            return;
        }

        $fail('service-feedback.validation.contact_invalid')->translate();
    }
}

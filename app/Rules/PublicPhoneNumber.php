<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A phone number a public submitter can be called back on: digits with an
 * optional leading +, spaces, dashes and parentheses, at least seven digits
 * and at most fifteen (E.164). The same shape PublicContact accepts.
 */
final class PublicPhoneNumber implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $value = is_string($value) ? trim($value) : '';

        if ($value === '') {
            return;
        }

        $digits = preg_match_all('/[0-9]/', $value);

        if (preg_match('/^\+?[0-9][0-9 ()\-]{6,19}$/', $value) !== 1 || $digits < 7 || $digits > 15) {
            $fail('service-feedback.validation.phone_invalid')->translate();
        }
    }
}

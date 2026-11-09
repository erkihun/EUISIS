<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\Phone\PublicContactPhone;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** An institution's public contact number, in a form PublicContactPhone accepts. */
final class PublicContactPhoneNumber implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return;
        }

        if (! is_string($value) || PublicContactPhone::parse($value) === null) {
            $fail('organizations.public_contact_phone_invalid')->translate();
        }
    }
}

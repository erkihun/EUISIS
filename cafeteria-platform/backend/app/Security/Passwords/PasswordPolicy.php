<?php

declare(strict_types=1);

namespace App\Security\Passwords;

use App\Services\CoreSettings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * The password rules for provider-portal accounts, from the platform's
 * Security settings (the same minimum length and history count the
 * administrator accounts follow).
 */
final class PasswordPolicy
{
    public function __construct(private readonly CoreSettings $settings) {}

    public function minLength(): int
    {
        return max(8, $this->settings->int('security.password_min_length'));
    }

    public function maxLength(): int
    {
        return 128;
    }

    public function historyCount(): int
    {
        return max(0, $this->settings->int('security.password_history_count'));
    }

    /**
     * @param  array<string, mixed>  $identity  names and addresses the password must not contain
     * @return list<mixed>
     */
    public function rules(?Model $account = null, array $identity = [], bool $confirmed = true): array
    {
        $rules = ['required', 'string', 'max:'.$this->maxLength(), Password::min($this->minLength())->letters()->numbers()];
        if ($confirmed) {
            $rules[] = 'confirmed';
        }
        $words = array_filter(array_map(fn ($value): string => is_string($value) ? mb_strtolower(trim($value)) : '', [
            ...array_values($identity),
            $account?->getAttribute('name'), $account?->getAttribute('email'), $account?->getAttribute('username'),
        ]), fn (string $word): bool => mb_strlen($word) >= 4);
        if ($words !== []) {
            $rules[] = function (string $attribute, mixed $value, \Closure $fail) use ($words): void {
                $password = mb_strtolower((string) $value);
                foreach ($words as $word) {
                    if (str_contains($password, $word)) {
                        $fail(__('password-policy.personal_information'));

                        return;
                    }
                }
            };
        }

        return $rules;
    }

    /** A one-time password with letters, digits and symbols that passes rules(). */
    public function generateTemporaryPassword(): string
    {
        return Str::password(max(12, $this->minLength()), letters: true, numbers: true, symbols: true, spaces: false);
    }
}

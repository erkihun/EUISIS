<?php

declare(strict_types=1);

namespace App\Security\Passwords\Rules;

use App\Security\Passwords\PasswordHistoryStore;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;

/**
 * Rejects the current password and the last N previous ones.
 *
 * Salted hashes differ for the same plaintext, so each stored hash is checked
 * with Hash::check — never compared with a freshly made hash. The message
 * never says which one matched. The change itself re-checks under a row lock
 * (PasswordLifecycle), so two concurrent requests cannot both pass.
 */
final class PasswordNotPreviouslyUsed implements ValidationRule
{
    public function __construct(private readonly Model $account, private readonly int $historyCount) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && app(PasswordHistoryStore::class)->isReused($this->account, $value, $this->historyCount)) {
            app(PasswordHistoryStore::class)->auditReuseRejected($this->account);
            $fail(__('password-policy.reused', ['count' => $this->historyCount]));
        }
    }
}

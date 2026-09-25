<?php

declare(strict_types=1);

namespace App\Security\Passwords\Rules;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\User;
use App\Security\Passwords\BreachCheckResult;
use App\Security\Passwords\CompromisedPasswordChecker;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Rejects a password found in known breach corpora.
 *
 * When the breach service cannot answer, the outcome is explicit, never a
 * silent pass:
 *   privileged account  -> refused ("could not be checked, try again")
 *   other accounts      -> accepted; a warning is logged (no password data)
 * The local blocklist and every other rule still apply either way.
 */
final class PasswordNotCompromised implements ValidationRule
{
    public function __construct(
        private readonly CompromisedPasswordChecker $checker,
        private readonly bool $privileged,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        $result = $this->checker->check($value);

        if ($result === BreachCheckResult::Compromised) {
            $this->audit();
            $fail(__('password-policy.compromised'));

            return;
        }

        if ($result === BreachCheckResult::Unavailable) {
            if ($this->privileged && (bool) config('security.passwords.breach_check.fail_closed_for_privileged', true)) {
                $fail(__('password-policy.breach_check_unavailable'));

                return;
            }

            Log::warning('Password breach check unavailable; password accepted under the fail-open policy for non-privileged accounts.');
        }
    }

    private function audit(): void
    {
        try {
            $actor = request()->user();
            app(WriteAuditLogAction::class)->execute(
                AuditEventType::PasswordCompromisedRejected,
                $actor instanceof User ? $actor : null,
                reason: 'password_found_in_breach_corpus',
            );
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}

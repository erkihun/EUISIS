<?php

declare(strict_types=1);

namespace App\Security\Passwords;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\User;
use App\Notifications\PasswordSecurityNotification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The one way a password is replaced, for every account type.
 *
 * Callers validate first with PasswordPolicy::rules() (and verify the current
 * password where the user is changing their own). Then, in one transaction:
 *
 *   1. lock the account row, so concurrent changes serialize
 *   2. re-check reuse against the locked state (the second of two racing
 *      requests sees the first one's password as "current" and is refused)
 *   3. move the CURRENT hash into history, then keep only the newest N
 *   4. store the new Argon2id hash
 *   5. set must_change_password / password_changed_at
 *   6. rotate remember_token (kills any remember-me cookie)
 *   7. audit (no password, no hash)
 *
 * After commit: the caller's model instance is refreshed (so the current
 * session re-binds to the new hash and stays signed in, while
 * AuthenticateSession signs out every other session), and the holder is
 * notified without the password.
 */
final class PasswordLifecycle
{
    public const KIND_CHANGED = 'changed';

    public const KIND_RESET = 'reset';

    public const KIND_ADMIN_RESET = 'admin_reset';

    public function __construct(
        private readonly PasswordPolicy $policy,
        private readonly PasswordHistoryStore $history,
        private readonly WriteAuditLogAction $writeAuditLog,
    ) {}

    public function change(
        Model $account,
        #[\SensitiveParameter] string $newPassword,
        AuditEventType $event,
        string $kind = self::KIND_CHANGED,
        bool $mustChange = false,
        ?User $actor = null,
        ?string $reason = null,
    ): void {
        $historyCount = $this->policy->historyCount();

        DB::transaction(function () use ($account, $newPassword, $event, $mustChange, $actor, $reason, $historyCount): void {
            /** @var Model $locked */
            $locked = $account->newQueryWithoutScopes()->whereKey($account->getKey())->lockForUpdate()->firstOrFail();

            if ($this->history->isReused($locked, $newPassword, $historyCount)) {
                throw ValidationException::withMessages([
                    'password' => __('password-policy.reused', ['count' => $historyCount]),
                ]);
            }

            $this->history->push($locked, (string) ($locked->getAttributes()['password'] ?? ''), $historyCount);

            $columns = $locked->getAttributes();
            $attributes = ['password' => Hash::make($newPassword)];

            if (array_key_exists('must_change_password', $columns)) {
                $attributes['must_change_password'] = $mustChange;
            }
            if (array_key_exists('password_changed_at', $columns)) {
                $attributes['password_changed_at'] = $mustChange ? null : now();
            }
            if (! $mustChange && array_key_exists('first_login_at', $columns) && $locked->getAttribute('first_login_at') === null) {
                $attributes['first_login_at'] = now();
            }
            if (array_key_exists('remember_token', $columns)) {
                $attributes['remember_token'] = Str::random(60);
            }

            $locked->forceFill($attributes)->save();

            $this->writeAuditLog->execute(
                eventType: $event,
                actor: $actor ?? ($locked instanceof User ? $locked : null),
                auditable: $locked,
                newValues: ['must_change_password' => $mustChange],
                reason: $reason ?? $event->value,
                request: request(),
            );
        });

        $account->refresh();

        // After the OUTERMOST transaction commits: a caller whose own work
        // later rolls back must not have told the holder anything.
        DB::afterCommit(fn () => $this->notify($account, $kind));
    }

    /**
     * Give the account a new random one-time password that must be replaced
     * at the next sign-in. Returns the plaintext ONCE, for the administrator
     * to hand over; it is never stored or logged.
     */
    public function assignTemporaryPassword(Model $account, ?User $actor, ?string $reason = null): string
    {
        $temporary = $this->policy->generateTemporaryPassword();

        $this->change(
            $account,
            $temporary,
            AuditEventType::TemporaryPasswordAssigned,
            self::KIND_ADMIN_RESET,
            mustChange: true,
            actor: $actor,
            reason: $reason ?? 'temporary_password_assigned',
        );

        return $temporary;
    }

    private function notify(Model $account, string $kind): void
    {
        if (! method_exists($account, 'notify')) {
            return;
        }

        try {
            $account->notify(new PasswordSecurityNotification($kind, now()->toIso8601String()));
        } catch (Throwable $exception) {
            // A mail outage must not undo a completed password change.
            report($exception);
        }
    }
}

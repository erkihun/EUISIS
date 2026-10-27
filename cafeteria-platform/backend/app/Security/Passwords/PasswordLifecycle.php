<?php

declare(strict_types=1);

namespace App\Security\Passwords;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Password changes for provider-portal accounts: the hash, the "must change
 * at next sign-in" flag, the change date and an audit row. A password the
 * account used recently (Security › password history) is refused.
 */
final class PasswordLifecycle
{
    public const KIND_CHANGED = 'changed';

    public const KIND_RESET = 'reset';

    public const KIND_ADMIN_RESET = 'admin_reset';

    public function __construct(
        private readonly PasswordPolicy $policy,
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
        DB::transaction(function () use ($account, $newPassword, $event, $kind, $mustChange, $actor, $reason): void {
            /** @var Model $locked */
            $locked = $account->newQueryWithoutScopes()->whereKey($account->getKey())->lockForUpdate()->firstOrFail();

            $history = (array) ($locked->getAttribute('password_history') ?? []);
            $recent = array_slice([$locked->getAttribute('password'), ...$history], 0, $this->policy->historyCount());
            foreach (array_filter($recent) as $hash) {
                if (is_string($hash) && Hash::check($newPassword, $hash)) {
                    throw ValidationException::withMessages([
                        'password' => __('password-policy.reused', ['count' => $this->policy->historyCount()]),
                    ]);
                }
            }

            $columns = $locked->getConnection()->getSchemaBuilder()->getColumnListing($locked->getTable());
            $values = ['password' => Hash::make($newPassword)];
            if (in_array('must_change_password', $columns, true)) {
                $values['must_change_password'] = $mustChange;
            }
            if (in_array('password_changed_at', $columns, true)) {
                $values['password_changed_at'] = now();
            }
            if (in_array('password_history', $columns, true)) {
                $values['password_history'] = array_slice(array_values(array_filter([$locked->getAttribute('password'), ...$history])), 0, max(1, $this->policy->historyCount()));
            }
            $locked->forceFill($values)->saveQuietly();
            $account->setRawAttributes($locked->getAttributes(), true);

            $this->writeAuditLog->execute($event, $actor, $locked, null, null, ['kind' => $kind, 'must_change_password' => $mustChange], $reason);
        });
    }

    /**
     * A one-time password the account must replace at the next sign-in.
     * Returns the plaintext ONCE, for the administrator to hand over.
     */
    public function assignTemporaryPassword(Model $account, ?User $actor, ?string $reason = null): string
    {
        $temporary = $this->policy->generateTemporaryPassword();

        $this->change($account, $temporary, AuditEventType::AdminPasswordReset, self::KIND_ADMIN_RESET, mustChange: true, actor: $actor, reason: $reason);

        return $temporary;
    }
}

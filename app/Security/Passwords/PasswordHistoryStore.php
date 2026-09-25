<?php

declare(strict_types=1);

namespace App\Security\Passwords;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\PasswordHistory;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Throwable;

/**
 * Previous password hashes per account. The only reader and writer of
 * `password_histories`; hashes never leave this class except into
 * Hash::check.
 */
final class PasswordHistoryStore
{
    /** Current password or any of the last $count previous ones. */
    public function isReused(Model $account, #[\SensitiveParameter] string $candidate, int $count): bool
    {
        $current = (string) ($account->getAttributes()['password'] ?? '');
        if ($current !== '' && Hash::check($candidate, $current)) {
            return true;
        }

        if ($count <= 0) {
            return false;
        }

        foreach ($this->recentHashes($account, $count) as $hash) {
            if (Hash::check($candidate, $hash)) {
                return true;
            }
        }

        return false;
    }

    /** Record the hash being replaced, then keep only the newest $count. */
    public function push(Model $account, string $replacedHash, int $count): void
    {
        if ($replacedHash !== '' && $count > 0) {
            PasswordHistory::query()->create([
                'authenticatable_type' => $account->getMorphClass(),
                'authenticatable_id' => (string) $account->getKey(),
                'password_hash' => $replacedHash,
            ]);
        }

        $this->prune($account, $count);
    }

    public function prune(Model $account, int $count): void
    {
        $owned = $this->owned($account);

        $keep = $count > 0
            ? (clone $owned)->orderByDesc('id')->limit($count)->pluck('id')->all()
            : [];

        (clone $owned)->whereNotIn('id', $keep)->delete();
    }

    public function auditReuseRejected(Model $account): void
    {
        try {
            $actor = request()->user();
            app(WriteAuditLogAction::class)->execute(
                AuditEventType::PasswordReuseRejected,
                $actor instanceof User ? $actor : null,
                $account,
                reason: 'password_matches_current_or_recent',
            );
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /** @return list<string> */
    private function recentHashes(Model $account, int $count): array
    {
        return $this->owned($account)->orderByDesc('id')->limit($count)->pluck('password_hash')->all();
    }

    private function owned(Model $account)
    {
        return PasswordHistory::query()
            ->where('authenticatable_type', $account->getMorphClass())
            ->where('authenticatable_id', (string) $account->getKey());
    }
}

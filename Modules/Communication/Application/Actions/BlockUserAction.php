<?php

declare(strict_types=1);

namespace Modules\Communication\Application\Actions;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;
use Modules\Communication\Application\Services\CommunicationAccess;
use Modules\Communication\Domain\Models\CommunicationBlock;

/**
 * Blocking: neither person can start a direct conversation, post in their
 * existing one, or add the other to a group. The blocked person is not told;
 * they see the same "cannot message this person" as for anyone unavailable.
 */
final class BlockUserAction
{
    public function __construct(
        private readonly CommunicationAccess $access,
        private readonly WriteAuditLogAction $audit,
    ) {}

    public function block(User $user, User $target): void
    {
        if (! $this->access->canUse($user)) {
            throw new AuthorizationException;
        }
        if ((int) $user->getKey() === (int) $target->getKey()) {
            throw ValidationException::withMessages(['user_id' => __('communication.errors.block_self')]);
        }
        try {
            (new CommunicationBlock)->forceFill(['blocker_id' => $user->getKey(), 'blocked_id' => $target->getKey(), 'created_at' => now()])->save();
        } catch (UniqueConstraintViolationException) {
            return;
        }
        $this->access->forget();
        $this->audit->execute(AuditEventType::CommunicationUserBlocked, $user, $target, null, null, ['blocked' => true]);
    }

    public function unblock(User $user, User $target): void
    {
        $deleted = CommunicationBlock::query()->where('blocker_id', $user->getKey())->where('blocked_id', $target->getKey())->delete();
        if ($deleted > 0) {
            $this->access->forget();
            $this->audit->execute(AuditEventType::CommunicationUserBlocked, $user, $target, null, ['blocked' => true], ['blocked' => false]);
        }
    }
}

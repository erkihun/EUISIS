<?php

declare(strict_types=1);

namespace Modules\Communication\Application\Actions;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Communication\Application\Events\ConversationCreated;
use Modules\Communication\Application\Services\CommunicationAccess;
use Modules\Communication\Application\Services\CommunicationBroadcaster;
use Modules\Communication\Application\Services\CommunicationDirectory;
use Modules\Communication\Domain\Enums\ConversationType;
use Modules\Communication\Domain\Enums\MemberRole;
use Modules\Communication\Domain\Enums\MembershipSource;
use Modules\Communication\Domain\Models\CommunicationBlock;
use Modules\Communication\Domain\Models\CommunicationUserSetting;
use Modules\Communication\Domain\Models\Conversation;
use Modules\Communication\Domain\Models\ConversationMember;

/**
 * Opens the one direct conversation between two people (idempotent: the pair
 * key is unique, and two simultaneous starts end on the same row). The other
 * person must be within the actor's directory reach, accept direct messages,
 * and not be blocked either way — the error never says which.
 */
final class StartDirectConversationAction
{
    public function __construct(
        private readonly CommunicationAccess $access,
        private readonly CommunicationDirectory $directory,
        private readonly CommunicationBroadcaster $broadcaster,
    ) {}

    public function execute(User $actor, User $other): Conversation
    {
        if (! $this->access->canUse($actor) || ! $actor->can('messages.send')) {
            throw new AuthorizationException;
        }
        $key = Conversation::directKey((int) $actor->getKey(), (int) $other->getKey());
        if (($existing = Conversation::query()->where('direct_key', $key)->first()) !== null) {
            return $existing;
        }

        if (! $this->directory->canReach($actor, $other) || ! $other->can('communication.access')
            || CommunicationBlock::between((int) $actor->getKey(), (int) $other->getKey())
            || ! CommunicationUserSetting::for($other)->allow_direct_messages) {
            throw ValidationException::withMessages(['user_id' => __('communication.errors.recipient_unavailable')]);
        }

        try {
            $conversation = DB::transaction(function () use ($actor, $other, $key): Conversation {
                $conversation = (new Conversation)->forceFill([
                    'type' => ConversationType::Direct,
                    'direct_key' => $key,
                    'created_by' => $actor->getKey(),
                    'last_message_at' => now(),
                ]);
                $conversation->save();
                foreach ([$actor, $other] as $user) {
                    (new ConversationMember)->forceFill([
                        'conversation_id' => $conversation->getKey(), 'user_id' => $user->getKey(),
                        'role' => MemberRole::Member, 'source' => MembershipSource::Manual,
                        'added_by' => $actor->getKey(), 'joined_at' => now(),
                    ])->save();
                }

                return $conversation;
            });
        } catch (UniqueConstraintViolationException) {
            return Conversation::query()->where('direct_key', $key)->firstOrFail();
        }

        $this->broadcaster->send(new ConversationCreated($conversation->getKey(), [(int) $actor->getKey(), (int) $other->getKey()]));

        return $conversation;
    }
}

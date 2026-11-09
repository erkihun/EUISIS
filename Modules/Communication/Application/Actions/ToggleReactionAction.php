<?php

declare(strict_types=1);

namespace Modules\Communication\Application\Actions;

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Modules\Communication\Application\Events\ReactionAdded;
use Modules\Communication\Application\Events\ReactionRemoved;
use Modules\Communication\Application\Presenters\MessageResource;
use Modules\Communication\Application\Services\CommunicationBroadcaster;
use Modules\Communication\Domain\Models\Message;
use Modules\Communication\Domain\Models\MessageReaction;

/** Adds or removes one person's reaction (one of each emoji per message, enforced by a unique index). */
final class ToggleReactionAction
{
    public function __construct(private readonly CommunicationBroadcaster $broadcaster) {}

    /** @return list<array{emoji: string, count: int, user_ids: list<int>}> the message's reaction summary */
    public function execute(Message $message, User $user, string $emoji, bool $add): array
    {
        Gate::forUser($user)->authorize('react', $message);
        if (! in_array($emoji, (array) config('communication.reactions', []), true)) {
            throw ValidationException::withMessages(['emoji' => __('communication.errors.reaction_invalid')]);
        }

        $query = MessageReaction::query()->where('message_id', $message->getKey())->where('user_id', $user->getKey())->where('emoji', $emoji);
        if ($add && ! $query->exists()) {
            try {
                (new MessageReaction)->forceFill(['message_id' => $message->getKey(), 'user_id' => $user->getKey(), 'emoji' => $emoji])->save();
            } catch (UniqueConstraintViolationException) {
                // Added twice at once: the index kept one.
            }
        } elseif (! $add) {
            $query->delete();
        }

        $summary = MessageResource::reactionSummary(MessageReaction::query()->where('message_id', $message->getKey())->get());
        $this->broadcaster->send($add
            ? new ReactionAdded($message->conversation_id, $message->getKey(), $summary)
            : new ReactionRemoved($message->conversation_id, $message->getKey(), $summary));

        return $summary;
    }
}

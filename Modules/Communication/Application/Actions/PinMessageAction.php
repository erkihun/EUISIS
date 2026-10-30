<?php

declare(strict_types=1);

namespace Modules\Communication\Application\Actions;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Modules\Communication\Domain\Models\ConversationPin;
use Modules\Communication\Domain\Models\Message;

/** Pins or unpins a message of the conversation (ConversationPolicy::pin). */
final class PinMessageAction
{
    public function execute(Message $message, User $user, bool $pin): void
    {
        Gate::forUser($user)->authorize('pin', $message->conversation);
        if ($message->trashed()) {
            throw ValidationException::withMessages(['message' => __('communication.errors.message_deleted')]);
        }
        $query = ConversationPin::query()->where('conversation_id', $message->conversation_id)->where('message_id', $message->getKey());
        if (! $pin) {
            $query->delete();

            return;
        }
        if ($query->exists()) {
            return;
        }
        if (ConversationPin::query()->where('conversation_id', $message->conversation_id)->count() >= (int) config('communication.max_pins', 50)) {
            throw ValidationException::withMessages(['message' => __('communication.errors.too_many_pins')]);
        }
        (new ConversationPin)->forceFill(['conversation_id' => $message->conversation_id, 'message_id' => $message->getKey(), 'pinned_by' => $user->getKey(), 'pinned_at' => now()])->save();
    }
}

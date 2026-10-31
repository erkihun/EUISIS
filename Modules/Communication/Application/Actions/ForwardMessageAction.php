<?php

declare(strict_types=1);

namespace Modules\Communication\Application\Actions;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Modules\Communication\Domain\Models\Conversation;
use Modules\Communication\Domain\Models\Message;
use Modules\Communication\Domain\Models\MessageAttachment;

/**
 * Forwards a message to up to five conversations the person may post in. The
 * file is not copied: the new attachment rows point at the same private object.
 */
final class ForwardMessageAction
{
    public const MAX_TARGETS = 5;

    public function __construct(private readonly SendMessageAction $send) {}

    /**
     * @param  list<string>  $conversationIds
     * @return list<Message>
     */
    public function execute(Message $message, User $user, array $conversationIds): array
    {
        Gate::forUser($user)->authorize('forward', $message);
        $conversationIds = array_values(array_unique($conversationIds));
        if ($conversationIds === [] || count($conversationIds) > self::MAX_TARGETS) {
            throw ValidationException::withMessages(['conversation_ids' => __('communication.errors.forward_targets', ['max' => self::MAX_TARGETS])]);
        }

        $sent = [];
        foreach (Conversation::query()->whereIn('id', $conversationIds)->get() as $target) {
            Gate::forUser($user)->authorize('sendMessage', $target);
            $copies = $message->attachments->map(function (MessageAttachment $a) use ($target, $user): string {
                $copy = $a->replicate(['message_id']);
                $copy->forceFill(['conversation_id' => $target->getKey(), 'uploaded_by' => $user->getKey(), 'message_id' => null])->save();

                return $copy->getKey();
            })->all();
            $sent[] = $this->send->execute($target, $user, [
                'body' => $message->body,
                'attachment_ids' => $copies,
                'forwarded_from_message_id' => $message->getKey(),
            ]);
        }

        return $sent;
    }
}

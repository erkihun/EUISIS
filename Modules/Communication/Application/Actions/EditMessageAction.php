<?php

declare(strict_types=1);

namespace Modules\Communication\Application\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Modules\Communication\Application\Events\MessageEdited;
use Modules\Communication\Application\Presenters\MessageResource;
use Modules\Communication\Application\Services\CommunicationBroadcaster;
use Modules\Communication\Application\Services\MentionParser;
use Modules\Communication\Domain\Enums\MentionType;
use Modules\Communication\Domain\Models\ConversationMember;
use Modules\Communication\Domain\Models\Message;
use Modules\Communication\Domain\Models\MessageMention;

/** The sender changes their message inside the edit window (MessagePolicy::update). */
final class EditMessageAction
{
    public function __construct(
        private readonly MentionParser $mentions,
        private readonly CommunicationBroadcaster $broadcaster,
    ) {}

    public function execute(Message $message, User $actor, string $body): Message
    {
        Gate::forUser($actor)->authorize('update', $message);
        $body = rtrim($body);
        if ($body === '' && ! $message->attachments()->exists()) {
            throw ValidationException::withMessages(['body' => __('communication.errors.empty_message')]);
        }
        $parsed = $this->mentions->parse($body);
        if (($parsed['channel'] || $parsed['everyone']) && ! str_contains((string) $message->body, $parsed['everyone'] ? '<!everyone>' : '<!channel>')) {
            // A broadcast cannot be smuggled in by editing.
            throw ValidationException::withMessages(['body' => __('communication.errors.broadcast_mention_forbidden')]);
        }

        DB::transaction(function () use ($message, $body, $parsed): void {
            $message->forceFill(['body' => $body === '' ? null : $body, 'edited_at' => now()])->save();
            MessageMention::query()->where('message_id', $message->getKey())->where('mention_type', MentionType::User->value)->delete();
            $ids = $parsed['users'] === [] ? [] : ConversationMember::query()->where('conversation_id', $message->conversation_id)
                ->whereNull('left_at')->whereIn('user_id', $parsed['users'])->where('user_id', '<>', $message->sender_id)->pluck('user_id')->all();
            foreach ($ids as $id) {
                (new MessageMention)->forceFill(['message_id' => $message->getKey(), 'conversation_id' => $message->conversation_id, 'mentioned_user_id' => $id, 'mention_type' => MentionType::User->value])->save();
            }
        });

        $message->load(['sender:id,name', 'attachments', 'reactions', 'mentions.mentionedUser:id,name', 'parent.sender:id,name']);
        $this->broadcaster->send(new MessageEdited($message->conversation_id, $message->getKey(), (new MessageResource($message))->resolve()));

        return $message;
    }
}

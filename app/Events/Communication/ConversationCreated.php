<?php

declare(strict_types=1);

namespace App\Events\Communication;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** A conversation was created; its members refresh their conversation list. */
class ConversationCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /** Broadcast only once the transaction that caused it has committed. */
    public bool $afterCommit = true;

    public function __construct(public readonly string $conversationId, public readonly array $memberIds)
    {
    }

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [...array_map(fn (int $id) => new PrivateChannel('communication.user.'.$id), $this->memberIds)];
    }

    public function broadcastAs(): string
    {
        return 'conversation.created';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['conversation_id' => $this->conversationId];
    }
}

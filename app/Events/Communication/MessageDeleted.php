<?php

declare(strict_types=1);

namespace App\Events\Communication;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** A message was deleted; members show "This message was deleted." (no content travels). */
class MessageDeleted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /** Broadcast only once the transaction that caused it has committed. */
    public bool $afterCommit = true;

    /** Realtime can run on its own worker (config communication.broadcast_queue); null keeps the default queue. */
    public function broadcastQueue(): ?string
    {
        return config('communication.broadcast_queue');
    }

    public function __construct(public readonly string $conversationId, public readonly string $messageId)
    {
    }

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('communication.conversation.'.$this->conversationId)];
    }

    public function broadcastAs(): string
    {
        return 'message.deleted';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['conversation_id' => $this->conversationId, 'message_id' => $this->messageId];
    }
}

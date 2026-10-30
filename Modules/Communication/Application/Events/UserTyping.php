<?php

declare(strict_types=1);

namespace Modules\Communication\Application\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Someone is typing. Never stored; throttled server-side; not sent back to the typist. */
class UserTyping implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly string $conversationId, public readonly int $userId, public readonly string $name)
    {
        $this->dontBroadcastToCurrentUser();
    }

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('communication.conversation.'.$this->conversationId)];
    }

    public function broadcastAs(): string
    {
        return 'user.typing';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['conversation_id' => $this->conversationId, 'user_id' => $this->userId, 'name' => $this->name];
    }
}

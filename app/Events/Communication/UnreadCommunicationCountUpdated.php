<?php

declare(strict_types=1);

namespace App\Events\Communication;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Someone's communication unread total may have changed: the top-bar Messages
 * badge fetches the (cached) summary again. The event carries no count, so
 * nothing is computed per recipient when a message reaches many people.
 */
class UnreadCommunicationCountUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /** Broadcast only once the transaction that caused it has committed. */
    public bool $afterCommit = true;

    /** Realtime can run on its own worker (config communication.broadcast_queue); null keeps the default queue. */
    public function broadcastQueue(): ?string
    {
        return config('communication.broadcast_queue');
    }

    /** @param list<int> $userIds */
    public function __construct(public readonly array $userIds) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return array_map(fn (int $id) => new PrivateChannel('communication.user.'.$id), $this->userIds);
    }

    public function broadcastAs(): string
    {
        return 'unread.changed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [];
    }
}

<?php

declare(strict_types=1);

namespace Modules\Communication\Application\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** An announcement went live. One signal on a shared channel, no per-reader fan-out and no content: each client refetches its own inbox, where the audience is checked. */
class AnnouncementPublished implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /** Broadcast only once the transaction that caused it has committed. */
    public bool $afterCommit = true;

    /** Realtime can run on its own worker (config communication.broadcast_queue); null keeps the default queue. */
    public function broadcastQueue(): ?string
    {
        return config('communication.broadcast_queue');
    }

    public function __construct(public readonly string $announcementId, public readonly string $priority) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('communication.announcements')];
    }

    public function broadcastAs(): string
    {
        return 'announcement.published';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['announcement_id' => $this->announcementId, 'priority' => $this->priority];
    }
}

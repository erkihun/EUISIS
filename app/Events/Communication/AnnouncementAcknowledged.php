<?php

declare(strict_types=1);

namespace App\Events\Communication;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** A reader acknowledged an announcement; managers' report views refresh. */
class AnnouncementAcknowledged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /** Broadcast only once the transaction that caused it has committed. */
    public bool $afterCommit = true;

    public function __construct(public readonly string $announcementId, public readonly int $userId)
    {
    }

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('communication.user.'.$this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'announcement.acknowledged';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['announcement_id' => $this->announcementId];
    }
}

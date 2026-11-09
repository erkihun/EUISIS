<?php

declare(strict_types=1);

namespace Modules\Communication\Application\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** A task made from a message was assigned. */
class CommunicationTaskAssigned implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /** Broadcast only once the transaction that caused it has committed. */
    public bool $afterCommit = true;

    /** Realtime can run on its own worker (config communication.broadcast_queue); null keeps the default queue. */
    public function broadcastQueue(): ?string
    {
        return config('communication.broadcast_queue');
    }

    public function __construct(public readonly string $taskId, public readonly int $assigneeId) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('communication.user.'.$this->assigneeId)];
    }

    public function broadcastAs(): string
    {
        return 'task.assigned';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['task_id' => $this->taskId];
    }
}

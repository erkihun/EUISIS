<?php

declare(strict_types=1);

namespace App\Jobs\Communication;

use App\Models\Communication\Channel;
use App\Models\User;
use App\Services\Communication\ChannelMembershipSync;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Brings one automatic channel's membership in line with the organizational assignments. */
class SyncChannelMemberships implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 600;

    public function __construct(public string $channelId, public ?int $actorId = null) {}

    public function uniqueId(): string
    {
        return $this->channelId;
    }

    public function handle(ChannelMembershipSync $sync): void
    {
        $channel = Channel::query()->with('conversation')->find($this->channelId);
        if ($channel !== null) {
            $sync->syncChannel($channel, $this->actorId ? User::query()->find($this->actorId) : null);
        }
    }
}

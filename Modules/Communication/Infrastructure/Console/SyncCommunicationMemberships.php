<?php

declare(strict_types=1);

namespace Modules\Communication\Infrastructure\Console;

use Illuminate\Console\Command;
use Modules\Communication\Application\Services\ChannelMembershipSync;
use Modules\Communication\Domain\Models\Channel;
use Modules\Communication\Infrastructure\Jobs\SyncChannelMemberships;

/**
 * Re-checks automatic channel membership for every channel (or one). The
 * assignment observer keeps people in step as they move; this nightly pass
 * catches what no save announces, such as a role losing communication.access.
 */
class SyncCommunicationMemberships extends Command
{
    protected $signature = 'communication:sync-memberships {--channel= : One channel id} {--queue : Queue one job per channel instead of running now}';

    protected $description = 'Bring automatic Communication channel memberships in line with organizational assignments';

    public function handle(ChannelMembershipSync $sync): int
    {
        $query = Channel::query()->where('auto_membership', true)->whereNull('archived_at')->with('conversation')->orderBy('id');
        if ($this->option('channel')) {
            $query->whereKey($this->option('channel'));
        }

        $added = $removed = $count = 0;
        $query->chunk(100, function ($channels) use ($sync, &$added, &$removed, &$count): void {
            foreach ($channels as $channel) {
                $count++;
                if ($this->option('queue')) {
                    SyncChannelMemberships::dispatch($channel->getKey());

                    continue;
                }
                $result = $sync->syncChannel($channel);
                $added += $result['added'];
                $removed += $result['removed'];
            }
        });

        $this->info($this->option('queue')
            ? "Queued {$count} channel(s)."
            : "Checked {$count} channel(s): {$added} added, {$removed} removed.");

        return self::SUCCESS;
    }
}

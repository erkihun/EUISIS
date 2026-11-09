<?php

declare(strict_types=1);

namespace App\Console\Commands\Assistant;

use App\Services\Assistant\AssistantConversations;
use Illuminate\Console\Command;

/** Deletes assistant conversations past System Settings › Assistant › retention. Scheduled nightly. */
final class AssistantPrune extends Command
{
    protected $signature = 'assistant:prune';

    protected $description = 'Delete Agelgay Assistant conversations older than the configured retention';

    public function handle(AssistantConversations $conversations): int
    {
        $result = $conversations->prune();
        $this->info(sprintf('Pruned %d conversations (%d messages).', $result['deleted_conversations'], $result['deleted_messages']));

        return self::SUCCESS;
    }
}

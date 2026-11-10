<?php

declare(strict_types=1);

namespace Modules\Letters\Infrastructure\Console;

use Illuminate\Console\Command;
use Modules\Letters\Application\Services\LetterReminders;

/** Daily: routing steps due soon or overdue, letters awaiting receipt, drafts awaiting signature (docs/letters.md §Reminders). */
final class SendLetterRemindersCommand extends Command
{
    protected $signature = 'letters:send-reminders {--dry-run : Count what would be sent without sending}';

    protected $description = 'Remind addressees of routing steps due soon or overdue, registrars of letters awaiting receipt, and signatories of drafts awaiting signature.';

    public function handle(LetterReminders $reminders): int
    {
        $sent = $reminders->run((bool) $this->option('dry-run'));
        foreach ($sent as $kind => $count) {
            $this->line(sprintf('%-20s %d', $kind, $count));
        }

        return self::SUCCESS;
    }
}

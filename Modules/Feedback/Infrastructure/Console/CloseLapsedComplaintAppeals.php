<?php

declare(strict_types=1);

namespace Modules\Feedback\Infrastructure\Console;

use Illuminate\Console\Command;
use Modules\Feedback\Application\Services\PublicComplaintStageService;

/**
 * ADR 0035: a decided complaint whose appeal period passed without the
 * complainant accepting or appealing closes. With no appeal period set,
 * nothing lapses.
 */
class CloseLapsedComplaintAppeals extends Command
{
    protected $signature = 'public-complaints:close-lapsed-appeals';

    protected $description = 'Close public complaints whose appeal period passed with no answer from the complainant';

    public function handle(PublicComplaintStageService $stages): int
    {
        $this->info('Closed '.$stages->closeLapsed().' complaint(s) after the appeal period.');

        return self::SUCCESS;
    }
}

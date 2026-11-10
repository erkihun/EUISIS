<?php

declare(strict_types=1);

namespace Modules\Letters\Infrastructure\Console;

use Illuminate\Console\Command;
use Modules\Letters\Application\Services\LetterImageFitter;

/** One-off and safe to repeat: brings stored letterhead emblems and stamps down to the size they print at. */
final class FitLetterImagesCommand extends Command
{
    protected $signature = 'letters:fit-images {--dry-run : Count what would be fitted without changing a file}';

    protected $description = 'Shrink stored letterhead emblems and stamps that are larger than they print, so pages and PDFs do not run out of memory.';

    public function handle(LetterImageFitter $fitter): int
    {
        $report = $fitter->fitStored((bool) $this->option('dry-run'));

        $this->line(sprintf('%-10s %d', 'checked', $report['checked']));
        $this->line(sprintf('%-10s %d', 'fitted', $report['fitted']));
        $this->line(sprintf('%-10s %d', 'failed', count($report['failed'])));
        foreach ($report['failed'] as $failed) {
            $this->warn('  could not be decoded on this server (upload a smaller image): '.$failed);
        }

        return self::SUCCESS;
    }
}

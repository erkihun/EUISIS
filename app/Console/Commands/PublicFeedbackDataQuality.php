<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ServiceFeedback\PublicFeedbackDataQuality as Checks;
use Illuminate\Console\Command;

/**
 * Public feedback data quality (QA brief §90–91). Read-only unless
 * --repair is given, and then it applies only deterministic technical
 * repairs; everything ambiguous is listed as NEEDS_DATA_REVIEW.
 */
class PublicFeedbackDataQuality extends Command
{
    protected $signature = 'feedback:data-quality {--repair : Apply the deterministic technical repairs} {--json : Also print a machine-readable summary}';

    protected $description = 'Report public feedback data-quality findings; --repair fixes only what needs no judgement';

    public function handle(Checks $checks): int
    {
        if ($this->option('repair')) {
            $done = $checks->repair();
            $this->components->info('Repairs applied (deterministic only)');
            foreach ($done as $key => $count) {
                $this->components->twoColumnDetail($key, (string) $count);
            }
        }

        $findings = $checks->run();
        $this->components->info('Public feedback data quality');

        foreach ($findings as $finding) {
            $status = match (true) {
                $finding['count'] === 0 => '<fg=green>OK</>',
                $finding['severity'] === Checks::SEVERITY_FAIL => '<fg=red>FAIL</>',
                $finding['severity'] === Checks::SEVERITY_REVIEW => '<fg=magenta>NEEDS_DATA_REVIEW</>',
                $finding['severity'] === Checks::SEVERITY_WARN => '<fg=yellow>WARN</>',
                default => '<fg=blue>INFO</>',
            };
            $this->components->twoColumnDetail(
                sprintf('%s (%d)%s', $finding['label'], $finding['count'], $finding['repairable'] && $finding['count'] > 0 ? ' [repairable with --repair]' : ''),
                $status,
            );
            if ($finding['count'] > 0 && $finding['sample'] !== []) {
                $this->components->bulletList(['sample: '.implode(', ', $finding['sample'])]);
            }
        }

        if ($this->option('json')) {
            $this->line(json_encode(['findings' => $findings], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        $failures = array_filter($findings, fn (array $f): bool => $f['count'] > 0 && $f['severity'] === Checks::SEVERITY_FAIL);

        return $failures === [] ? self::SUCCESS : self::FAILURE;
    }
}

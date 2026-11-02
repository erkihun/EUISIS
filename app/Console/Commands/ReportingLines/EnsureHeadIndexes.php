<?php

declare(strict_types=1);

namespace App\Console\Commands\ReportingLines;

use App\Support\Database\HeadPositionIndexes;
use Illuminate\Console\Command;

/**
 * Installs the one-head-per-unit and one-head-per-organization indexes the
 * migration left out because legacy data still had duplicates (ADR 0025).
 * Run after the duplicates have been resolved; safe to repeat.
 */
class EnsureHeadIndexes extends Command
{
    protected $signature = 'reporting:ensure-head-indexes {--check : Report only; install nothing}';

    protected $description = 'Add the database indexes that keep one head position per unit and per organization (ADR 0025)';

    public function handle(): int
    {
        if ($this->option('check')) {
            foreach (HeadPositionIndexes::status() as $name => $present) {
                $this->line(sprintf('%s: %s', $name, $present ? 'present' : 'missing'));
            }
            foreach (HeadPositionIndexes::violations() as $name => $count) {
                $this->warn(sprintf('%s: %d duplicate head(s) block the index', $name, $count));
            }

            return HeadPositionIndexes::violations() === [] ? self::SUCCESS : self::FAILURE;
        }

        $result = HeadPositionIndexes::ensure();
        foreach ($result['present'] as $name) {
            $this->line("{$name}: already present");
        }
        foreach ($result['added'] as $name) {
            $this->info("{$name}: added");
        }
        foreach ($result['skipped'] as $name => $count) {
            $this->warn("{$name}: skipped, {$count} duplicate head(s) remain (see reporting:diagnose --preflight)");
        }

        return $result['skipped'] === [] ? self::SUCCESS : self::FAILURE;
    }
}

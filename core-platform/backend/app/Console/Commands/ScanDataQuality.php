<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\DataQualityScanner;
use App\Services\SystemHealth;
use Illuminate\Console\Command;

/** Runs the integrity checks and keeps the data-quality issues in step (ADR 0011); scheduled hourly. */
class ScanDataQuality extends Command
{
    protected $signature = 'core:data-quality-scan {--check= : Only this check}';

    protected $description = 'Open, update, reopen and resolve data-quality issues from the integrity checks';

    public function handle(DataQualityScanner $scanner): int
    {
        SystemHealth::recordRun('core:data-quality-scan');
        $rows = [];
        foreach ($scanner->scan($this->option('check') ?: null) as $code => $counts) {
            $rows[] = [$code, $counts['found'], $counts['opened'], $counts['reopened'], $counts['resolved']];
        }
        $this->table(['Check', 'Found', 'New', 'Reopened', 'Resolved'], $rows);

        return self::SUCCESS;
    }
}

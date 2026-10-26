<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\DataQualityScanner;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** A data-quality scan asked for from the Data Quality screen (ADR 0011); one at a time. */
class ScanDataQualityJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public function handle(DataQualityScanner $scanner): void
    {
        $scanner->scan();
    }
}

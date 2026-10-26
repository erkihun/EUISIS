<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;

/** A verification of the audit chain asked for from the Security Center (ADR 0010); one at a time. */
class VerifyAuditTrailJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public function __construct(public readonly int $userId) {}

    public function handle(): void
    {
        Artisan::call('core:audit-verify', ['--user' => $this->userId]);
    }
}

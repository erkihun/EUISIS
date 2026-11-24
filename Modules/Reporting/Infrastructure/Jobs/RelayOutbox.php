<?php

declare(strict_types=1);

namespace Modules\Reporting\Infrastructure\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Reporting\Application\Support\ReportingSettings;
use Modules\Reporting\Infrastructure\Outbox\DatabaseOutbox;

/** Relays stored integration events soon after the commit; the scheduled relay covers a lost job. */
final class RelayOutbox implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 60;

    public function handle(DatabaseOutbox $outbox, ReportingSettings $settings): void
    {
        // Paused: the rows stay pending and are relayed when the module is switched back on.
        if (! $settings->isPaused()) {
            $outbox->relay();
        }
    }
}

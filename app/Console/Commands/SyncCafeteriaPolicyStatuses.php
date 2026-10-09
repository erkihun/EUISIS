<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Cafeteria\Policy\CafeteriaPolicyWorkflowService;
use Illuminate\Console\Command;

/**
 * Daily bookkeeping: approved policies whose date has come become active (and
 * supersede their predecessor); ended ones become expired. Scans never wait
 * for this — which policy binds follows its dates, not its status label.
 */
class SyncCafeteriaPolicyStatuses extends Command
{
    protected $signature = 'cafeteria:sync-policy-statuses';

    protected $description = 'Mark cafeteria service policies active or expired according to their dates';

    public function handle(CafeteriaPolicyWorkflowService $workflow): int
    {
        $this->info($workflow->syncStatuses().' policy status change(s).');

        return self::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AuditHeads;
use App\Services\SystemHealth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Copies new audit seal heads off the database server (ADR 0012); scheduled every five minutes, catches up after a failure. */
class ShipAuditHeads extends Command
{
    protected $signature = 'core:audit-ship-heads';

    protected $description = 'Copy new audit seal heads to the off-server store (CORE_AUDIT_HEAD_DISK)';

    public function handle(AuditHeads $heads): int
    {
        SystemHealth::recordRun('core:audit-ship-heads');
        if (! $heads->configured()) {
            $this->warn('CORE_AUDIT_HEAD_DISK is not set: the seal heads exist only in this database and its log.');

            return self::SUCCESS;
        }
        try {
            $shipped = $heads->ship();
        } catch (Throwable $e) {
            Log::critical('Audit seal heads could not be shipped off the server.', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $this->error('Audit seal heads could not be shipped: '.$e::class);

            return self::FAILURE;
        }
        $this->info("{$shipped} seal head(s) shipped.");

        return self::SUCCESS;
    }
}

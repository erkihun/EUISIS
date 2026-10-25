<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AuditChain;
use Illuminate\Console\Command;

/** Seals the audit rows written since the last seal (ADR 0010); scheduled every five minutes. */
class SealAuditTrail extends Command
{
    protected $signature = 'core:audit-seal {--limit=5000 : Rows per seal} {--all : Keep sealing until nothing is left}';

    protected $description = 'Give new audit rows their sequence and chain hash, and sign the range';

    public function handle(AuditChain $chain): int
    {
        $limit = max(100, (int) $this->option('limit'));
        $total = 0;
        do {
            $sealed = $chain->seal($limit);
            $total += $sealed;
        } while ($this->option('all') && $sealed === $limit);
        $this->info("{$total} audit row(s) sealed.");

        return self::SUCCESS;
    }
}

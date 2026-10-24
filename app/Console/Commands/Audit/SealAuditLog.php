<?php

declare(strict_types=1);

namespace App\Console\Commands\Audit;

use App\Services\Audit\AuditLogSealer;
use Illuminate\Console\Command;

/**
 * Seals each complete day of the audit log (scheduled daily). Writes only the
 * seal rows; never changes an audit row.
 */
class SealAuditLog extends Command
{
    protected $signature = 'audit:seal';

    protected $description = 'Seal every complete day of the audit log with a keyed checksum (tamper evidence)';

    public function handle(AuditLogSealer $sealer): int
    {
        if (! $sealer->available()) {
            $this->error('The audit_log_seals table is missing: run php artisan migrate.');

            return self::FAILURE;
        }

        $sealed = $sealer->sealPendingDays();
        $this->info($sealed === [] ? 'Nothing to seal: the audit log is sealed through yesterday.' : 'Sealed '.count($sealed).' day(s), '.$sealed[0].' to '.end($sealed).'.');

        return self::SUCCESS;
    }
}

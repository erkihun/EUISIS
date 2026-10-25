<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AuditChain;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Recomputes the whole sealed audit chain (ADR 0010) and records the result
 * for the Security Center; fails (exit code 1) when anything does not match.
 */
class VerifyAuditTrail extends Command
{
    protected $signature = 'core:audit-verify {--user= : The account that asked for the check}';

    protected $description = 'Verify every sealed audit row, the chain and the seals';

    public function handle(AuditChain $chain): int
    {
        $id = DB::table('audit_verifications')->insertGetId(['started_at' => now(), 'run_by' => $this->option('user') ?: null]);
        $result = $chain->verify();
        DB::table('audit_verifications')->where('id', $id)->update([
            'finished_at' => now(), 'ok' => $result['ok'], 'checked_rows' => $result['checked'],
            'first_bad_sequence' => $result['first_bad_sequence'], 'message' => mb_substr($result['message'], 0, 1000),
        ]);
        if (! $result['ok']) {
            Log::critical('Audit trail verification failed.', $result);
            $this->error($result['message']);

            return self::FAILURE;
        }
        $this->info($result['message']);

        return self::SUCCESS;
    }
}

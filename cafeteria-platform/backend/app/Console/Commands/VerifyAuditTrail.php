<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AuditChain;
use App\Services\AuditHeads;
use App\Services\AuditWriter;
use App\Services\SystemHealth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Recomputes the whole sealed audit chain (ADR 0010) and records the result
 * for the Security Center; fails (exit code 1) when anything does not match.
 */
class VerifyAuditTrail extends Command
{
    protected $signature = 'core:audit-verify {--user= : The account that asked for the check}
        {--restored : The trail is a restored copy: seals shipped off the server after its last seal are reported, not failed}';

    protected $description = 'Verify every sealed audit row, the chain and the seals';

    public function handle(AuditChain $chain, AuditHeads $heads): int
    {
        SystemHealth::recordRun('core:audit-verify');
        $id = DB::table('audit_verifications')->insertGetId(['started_at' => now(), 'run_by' => $this->option('user') ?: null]);
        $result = $chain->verify();
        // The chain matches itself; its seals must also match the copies kept off the server (ADR 0012).
        if ($result['ok']) {
            $copies = $heads->verify((bool) $this->option('restored'));
            $result = $copies['ok'] ? [...$result, 'message' => $result['message'].' '.$copies['message']]
                : [...$copies, 'checked' => $result['checked']];
        }
        DB::table('audit_verifications')->where('id', $id)->update([
            'finished_at' => now(), 'ok' => $result['ok'], 'checked_rows' => $result['checked'],
            'first_bad_sequence' => $result['first_bad_sequence'], 'message' => mb_substr($result['message'], 0, 1000),
        ]);
        if (! $result['ok']) {
            Log::critical('Audit trail verification failed.', $result);
            // Recorded in the trail itself, which tells the security dashboard's holders (ADR 0011).
            app(AuditWriter::class)->writeFor('audit.verification_failed', null, 'AuditTrail', 'core_audit_logs', null, null,
                ['first_bad_sequence' => $result['first_bad_sequence']], mb_substr($result['message'], 0, 1000));
            $this->error($result['message']);

            return self::FAILURE;
        }
        $this->info($result['message']);

        return self::SUCCESS;
    }
}

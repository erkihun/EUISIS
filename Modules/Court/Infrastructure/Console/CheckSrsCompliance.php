<?php

declare(strict_types=1);

namespace Modules\Court\Infrastructure\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;

final class CheckSrsCompliance extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'court:compliance-check {--json : Emit machine-readable JSON}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check deployment-sensitive controls required by the CCMS SRS';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $checks = [
            $this->check('production_debug_disabled', ! app()->isProduction() || ! config('app.debug')),
            $this->check('https_url_configured', ! app()->isProduction() || str_starts_with((string) config('app.url'), 'https://')),
            $this->check('application_security_headers_enabled', (bool) config('court.compliance.security.app_headers_enabled')),
            $this->check('hsts_enabled', ! app()->isProduction() || (bool) config('court.compliance.security.hsts_enabled')),
            $this->check('asynchronous_queue', ! app()->isProduction() || config('queue.default') !== 'sync'),
            $this->check('file_security_schema', Schema::hasTable('court_file_security_records')),
            $this->check('legal_hold_schema', Schema::hasTable('court_legal_holds')),
            $this->check('audit_request_context', Schema::hasColumn('court_system_audits', 'request_id')),
            $this->check('malware_scanner', $this->scannerReady()),
            $this->check('clean_scan_required', (bool) config('court.compliance.uploads.require_clean_scan')),
            $this->check('automated_backup', (bool) config('court.compliance.continuity.automated_backup')),
            $this->check('encrypted_backup', (bool) config('court.compliance.continuity.encrypted')),
            $this->check('offsite_backup', (bool) config('court.compliance.continuity.offsite')),
            $this->check('restore_test_recorded', filled(config('court.compliance.continuity.restore_tested_at'))),
            $this->check('rpo_approved', is_numeric(config('court.compliance.continuity.rpo_hours'))),
            $this->check('rto_approved', is_numeric(config('court.compliance.continuity.rto_hours'))),
        ];

        $passed = collect($checks)->where('passed', true)->count();
        $total = count($checks);

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'passed' => $passed,
                'total' => $total,
                'ready' => $passed === $total,
                'checks' => $checks,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->table(['Control', 'Status'], array_map(
                fn (array $check): array => [$check['control'], $check['passed'] ? 'PASS' : 'FAIL'],
                $checks
            ));
            $this->line("{$passed}/{$total} deployment controls passed.");
        }

        return $passed === $total ? self::SUCCESS : self::FAILURE;
    }

    /** @return array{control: string, passed: bool} */
    private function check(string $control, bool $passed): array
    {
        return ['control' => $control, 'passed' => $passed];
    }

    private function scannerReady(): bool
    {
        if (config('court.compliance.uploads.scanner') !== 'clamav') {
            return false;
        }

        try {
            $process = new Process([(string) config('court.compliance.uploads.scanner_binary'), '--version']);
            $process->setTimeout(5);
            $process->run();

            return $process->isSuccessful();
        } catch (\Throwable) {
            return false;
        }
    }
}

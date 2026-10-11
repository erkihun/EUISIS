<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Backup\BackupStatusService;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Pre-go-live gate: runtime, infrastructure and operations settings that
 * security:production-check does not cover. Read-only; prints no secret.
 *
 * FAIL blocks a production deployment (exit 1); WARN needs a conscious
 * decision; INFO is context for the operator. Outside production FAILs are
 * shown as WARN unless --strict is given, like security:production-check.
 */
class ProductionReadiness extends Command
{
    protected $signature = 'production:readiness {--strict : Treat this environment as production}';

    protected $description = 'Read-only go-live gate: runtime, infrastructure and data-audit checks';

    private int $failures = 0;

    public function handle(Schedule $schedule): int
    {
        $production = app()->isProduction() || $this->option('strict');
        $this->components->info(sprintf('Environment: %s (%s)', config('app.env'), $production ? 'production rules enforced' : 'reported for awareness'));

        $this->section('Security configuration');
        $security = $this->call('security:production-check', $production ? ['--strict' => true] : []);
        $this->failures += $security === self::SUCCESS ? 0 : 1;

        $this->section('Application');
        $this->check('APP_ENV is production', app()->isProduction(), $production, 'Environment-specific safety rules key off APP_ENV=production.');
        $this->check('APP_URL uses https', str_starts_with((string) config('app.url'), 'https://'), $production, 'Card QR codes, reset links and signed URLs are built from APP_URL.');
        $this->check('Session SameSite is lax', config('session.same_site') === 'lax', $production, 'The employee portal uses first-party sign-in links; Strict breaks normal mobile browser and e-mail-link session continuity.');
        $qrBase = trim((string) config('id_cards.qr.base_url', ''));
        $this->check('Card QR base URL uses https', $qrBase === '' || str_starts_with($qrBase, 'https://'), $production, 'Printed cards carry this URL for years; it cannot be changed without reprinting.');
        $this->info_('Timezone', (string) config('app.timezone'));
        $this->info_('Trusted proxies', config('trustedproxy.proxies') === null ? 'none (correct only if clients reach this server directly)' : 'configured');

        $this->section('Database');
        try {
            DB::connection()->select('select 1');
            $this->check('Database reachable ('.DB::connection()->getDriverName().')', true, $production, '');
            $pending = $this->pendingMigrations();
            $this->check('No pending migrations', $pending === [], $production, 'Pending: '.implode(', ', array_slice($pending, 0, 10)));
            if (Schema::hasTable('failed_jobs')) {
                $failed = DB::table('failed_jobs')->count();
                $this->check('No failed queue jobs', $failed === 0, false, $failed.' failed job(s): inspect with queue:failed before go-live.');
            }
        } catch (Throwable $e) {
            $this->check('Database reachable', false, $production, class_basename($e));
        }

        $this->section('Queue, cache, mail, storage');
        $this->check('Queue is asynchronous', config('queue.default') !== 'sync', $production, 'The sync driver runs e-mails, SMS and exports inside the web request.');
        $this->check('Cache store persists', ! in_array(config('cache.default'), ['array', 'null'], true), $production, 'Login lockouts, OTP throttles and rate limits live in the cache.');
        $this->check('Cache store is shared across servers', config('cache.default') !== 'file', false, 'A file cache is per server: behind a load balancer, lockouts and throttles are per node.');
        $this->check('Mail is delivered', ! in_array(config('mail.default'), ['log', 'array'], true), $production, 'Password resets and notifications would be written to the log instead.');
        $this->check('Logs rotate', config('logging.default') !== 'single' && config('logging.channels.'.config('logging.default').'.driver') !== 'single', false, 'A single log file grows without bound; use daily or an external channel.');
        foreach (['storage/app', 'storage/framework/cache', 'storage/framework/sessions', 'storage/logs', 'bootstrap/cache'] as $path) {
            $this->check("{$path} is writable", is_writable(base_path($path)), $production, 'The web and queue users must be able to write here.');
        }

        $this->section('Multi-node and scale (docs/load-balancing.md)');
        $this->check('Trusted proxies configured', config('trustedproxy.proxies') !== null, false, 'Behind a load balancer HTTPS is not detected and every client shares the balancer IP for rate limits; set APP_TRUSTED_PROXIES.');
        $this->check('Sessions shared across nodes', ! in_array(config('session.driver'), ['file', 'array'], true), $production, 'A file session exists on one node only; use database or redis.');
        $this->check('Maintenance mode shared across nodes', config('app.maintenance.driver') !== 'file', false, 'The file driver puts only one node in maintenance; use APP_MAINTENANCE_DRIVER=cache with a shared store.');
        $this->check('Private files on shared storage', config('filesystems.disks.local.driver') !== 'local', false, 'Uploads on node-local disk are missing on the other nodes; set STORAGE_PRIVATE_DRIVER=s3 for a multi-node deployment.');
        $this->check('Public files on shared storage', config('filesystems.disks.public.driver') !== 'local', false, 'Logos and photos on node-local disk are missing on the other nodes; set STORAGE_PUBLIC_DRIVER=s3 for a multi-node deployment.');
        $retryAfter = (int) config('queue.connections.'.config('queue.default').'.retry_after', 0);
        $longestTimeout = $this->longestJobTimeout();
        $this->check("Queue retry_after ({$retryAfter}s) exceeds the longest job timeout ({$longestTimeout}s)", config('queue.default') === 'sync' || $retryAfter > $longestTimeout, $production, 'A job still running at retry_after is handed to a second worker and runs twice.');
        $notOnOneServer = collect($schedule->events())->reject(fn ($event) => $event->onOneServer)->map(fn ($event) => (string) ($event->command ?? $event->description))->values()->all();
        $this->check('Every scheduled task runs on one server', $notOnOneServer === [], $production, 'Duplicates on a multi-node scheduler: '.implode(', ', $notOnOneServer));
        $this->check('Transport Management is disabled (out of current scope)', ! (bool) config('features.transport'), false, 'FEATURE_TRANSPORT is on; Transport is a future phase and not part of this release.');
        $this->check('Legacy card QR (card id only) is refused at the counter', ! (bool) config('id_cards.accept_legacy_qr'), false, 'ID_CARD_ACCEPT_LEGACY_QR is on: anyone who knows a card id can present it (decision D-3). Turn it off once the reprint is done.');
        if (config('database.default') === 'pgsql') {
            $sslmode = (string) config('database.connections.pgsql.sslmode', 'prefer');
            $this->check("PostgreSQL TLS (sslmode={$sslmode})", in_array($sslmode, ['require', 'verify-ca', 'verify-full'], true), false, 'Database traffic leaves the host; use DB_SSLMODE=require or verify-full unless the network is private and trusted.');
        } else {
            $this->check('Database engine is PostgreSQL', false, false, 'PostgreSQL is the production engine (decision D-5).');
        }

        $this->section('PHP extensions');
        $driver = (string) config('database.connections.'.config('database.default').'.driver');
        $this->check("pdo_{$driver}", extension_loaded('pdo_'.$driver), $production, 'Required by the configured database.');
        foreach (['mbstring', 'openssl', 'intl', 'fileinfo'] as $extension) {
            $this->check($extension, extension_loaded($extension), $production, 'Used for Amharic text, encryption, locale formatting or upload checks.');
        }
        $usesRedis = collect([config('cache.default'), config('queue.default'), config('session.driver')])->contains('redis');
        $this->check('redis extension (phpredis)', ! $usesRedis || config('database.redis.client') !== 'phpredis' || extension_loaded('redis'), $production, 'Cache, queue or session use Redis through phpredis.');
        $this->check('imagick (card PNG export)', extension_loaded('imagick'), false, 'Without it PNG card export is refused; SVG and PDF still work.');

        $this->section('Operations');
        // --strict evaluates backups under production rules even outside production.
        $backup = app(BackupStatusService::class)->status(fresh: true, enforce: $production ?: null);
        $when = fn (?int $time) => $time ? gmdate('Y-m-d H:i', $time).' UTC' : 'none recorded';
        $this->info_('Backup & Recovery', $backup['overall_status']);
        $this->info_('Backup reason', ($backup['reason_code'] ?? 'NONE').' ('.$backup['driver'].')');
        $this->info_('Backup infrastructure / repository', $backup['infrastructure_status'].' / '.$backup['repository_status']);
        $this->info_('WAL archive / PITR', $backup['wal_status']);
        $this->info_('Last passed restore test', $when($backup['latest_restore_test_at']));
        $this->info_('Backup production blocker', $backup['production_blocker'] ? 'YES' : ($backup['enforced'] ? 'NO' : 'NO for this environment'));
        $this->check('Backup and recovery ready', $backup['readiness'] === 'READY', $production, $backup['message']);
        $this->check('Configuration cached', app()->configurationIsCached(), false, 'Run php artisan config:cache on deploy.');
        $this->check('Routes cached', app()->routesAreCached(), false, 'Run php artisan route:cache on deploy.');
        $this->info_('Scheduled tasks', count($schedule->events()).' — needs a cron entry running schedule:run every minute');
        $this->info_('Data audits', 'run data:audit-duplicates, structure:audit and cafeteria:audit-configuration against production data');

        if ($this->failures > 0) {
            $this->components->error($this->failures.' blocking item(s).');

            return self::FAILURE;
        }
        $this->components->info('No blocking items. Review every WARN before go-live.');

        return self::SUCCESS;
    }

    private function section(string $title): void
    {
        $this->newLine();
        $this->line("<options=bold>{$title}</>");
    }

    private function check(string $label, bool $passed, bool $blocking, string $why): void
    {
        if ($passed) {
            $this->components->twoColumnDetail($label, '<fg=green>PASS</>');

            return;
        }
        $this->failures += $blocking ? 1 : 0;
        $this->components->twoColumnDetail($label, $blocking ? '<fg=red>FAIL</>' : '<fg=yellow>WARN</>');
        if ($why !== '') {
            $this->components->bulletList([$why]);
        }
    }

    private function info_(string $label, string $value): void
    {
        $this->components->twoColumnDetail($label, "<fg=gray>{$value}</>");
    }

    /** @return list<string> */
    private function pendingMigrations(): array
    {
        $migrator = app('migrator');
        if (! $migrator->repositoryExists()) {
            return ['(migrations table missing)'];
        }
        $files = $migrator->getMigrationFiles(array_merge([database_path('migrations')], $migrator->paths()));
        $ran = $migrator->getRepository()->getRan();

        return array_values(array_diff(array_keys($files), $ran));
    }

    /** Longest `$timeout` declared by a queued job class in app/Jobs. */
    private function longestJobTimeout(): int
    {
        $longest = 60; // worker default
        foreach (glob(app_path('Jobs/**/*.php')) ?: [] as $file) {
            if (preg_match('/public\s+int\s+\$timeout\s*=\s*(\d+)/', (string) file_get_contents($file), $match)) {
                $longest = max($longest, (int) $match[1]);
            }
        }
        foreach (glob(app_path('Jobs/*.php')) ?: [] as $file) {
            if (preg_match('/public\s+int\s+\$timeout\s*=\s*(\d+)/', (string) file_get_contents($file), $match)) {
                $longest = max($longest, (int) $match[1]);
            }
        }

        return $longest;
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands;

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
}

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\CodeRuleEntityType;
use App\Models\CodeRule;
use App\Models\EmployeeFeedbackToken;
use App\Models\OrganizationFeedbackQrCode;
use App\Models\PublicFeedbackCategory;
use App\Models\PublicFeedbackRoutingRule;
use App\Services\ServiceFeedback\PublicFeedbackSettings;
use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Read-only health check of everything a citizen's feedback depends on, for a
 * server nobody can debug from a browser: pending migrations, the policy
 * switches, the QR codes and tokens that make a link usable, the numbering
 * rule a complaint needs, and the cache, session, queue and proxy settings
 * behind the form. Prints one line per check and exits 1 when one fails.
 */
class PublicFeedbackDoctor extends Command
{
    protected $signature = 'public-feedback:doctor';

    protected $description = 'Check everything the public feedback form and its administration depend on';

    /** @var list<array{0: string, 1: string, 2: string}> */
    private array $rows = [];

    public function handle(Migrator $migrator, PublicFeedbackSettings $settings): int
    {
        $this->checkMigrations($migrator);
        $this->checkPolicy($settings);
        $this->checkData();
        $this->checkInfrastructure();
        $this->checkWeb();
        $this->checkLog();

        $this->table(['Check', 'Status', 'Detail'], $this->rows);
        $failed = count(array_filter($this->rows, fn (array $row): bool => $row[1] === 'FAIL'));
        $warned = count(array_filter($this->rows, fn (array $row): bool => $row[1] === 'WARN'));
        $this->line(sprintf('%d failed, %d warnings.', $failed, $warned));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function checkMigrations(Migrator $migrator): void
    {
        try {
            if (! $migrator->repositoryExists()) {
                $this->add('Migrations', 'FAIL', 'The migrations table does not exist: run php artisan migrate --force');

                return;
            }
            $ran = $migrator->getRepository()->getRan();
            $files = array_keys($migrator->getMigrationFiles($migrator->paths() + [database_path('migrations')]));
            $pending = array_values(array_diff($files, $ran));
            $this->add('Migrations', $pending === [] ? 'OK' : 'FAIL', $pending === [] ? count($ran).' applied, none pending' : count($pending).' pending, first: '.$pending[0].' — run php artisan migrate --force');
        } catch (Throwable $e) {
            $this->add('Migrations', 'FAIL', 'Cannot read migration state: '.$e->getMessage());
        }
    }

    private function checkPolicy(PublicFeedbackSettings $settings): void
    {
        $this->add('Public feedback enabled', $settings->enabled() ? 'OK' : 'FAIL', $settings->enabled() ? 'System Settings › Public feedback › Enabled' : 'Switched off in System Settings › Public feedback: every link shows "not available"');
        $types = $settings->enabledTypes();
        $this->add('Submission types', $types === [] ? 'FAIL' : 'OK', $types === [] ? 'No type is enabled' : implode(', ', $types));
        $this->add('Identity policy', 'OK', 'feedback & complaint named; tip-off optional; evidence '.($settings->allowAttachments() ? 'allowed' : 'off').'; acknowledgement '.($settings->acknowledgementEnabled() ? 'on' : 'off'));
        $this->add('Rate limits', 'OK', sprintf('%d per QR and %d per address, each per 10 minutes', $settings->rateLimitPerSource(), $settings->rateLimitPerIp()));
    }

    private function checkData(): void
    {
        $organizationCodes = OrganizationFeedbackQrCode::query()->where('status', 'active')->count();
        $employeeTokens = EmployeeFeedbackToken::query()->where('status', 'active')->count();
        $this->add('Active institution QR codes', $organizationCodes > 0 ? 'OK' : 'WARN', $organizationCodes > 0 ? "{$organizationCodes} active" : 'None: generate one from an institution\'s "Feedback QR" page, or nobody has a link to open');
        $this->add('Active employee feedback QR tokens', $employeeTokens > 0 ? 'OK' : 'WARN', $employeeTokens > 0 ? "{$employeeTokens} active" : 'None generated yet (Employees › Feedback QR)');

        $categories = PublicFeedbackCategory::query()->active()->count();
        $this->add('Active categories', $categories > 0 ? 'OK' : 'WARN', $categories > 0 ? "{$categories} active" : 'No category: forms still work, submissions are uncategorised');

        $rules = PublicFeedbackRoutingRule::query()->where('is_active', true)->count();
        $this->add('Active routing rules', $rules > 0 ? 'OK' : 'WARN', $rules > 0 ? "{$rules} active" : 'No rule: suggestions and complaints arrive UNROUTED and must be assigned by hand');

        $rule = CodeRule::query()->where('active_scope_key', CodeRule::buildActiveScopeKey(CodeRuleEntityType::PublicServiceComplaint))->exists();
        $this->add('Complaint numbering rule', $rule ? 'OK' : 'FAIL', $rule ? 'Code rule for public complaints is active' : 'Missing: a complaint cannot be saved. Run php artisan migrate --force (2026_10_10_000200 creates it) or add a code rule for Public service complaint');
    }

    private function checkInfrastructure(): void
    {
        $cacheStore = (string) config('cache.default');
        try {
            Cache::put('public-feedback:doctor', 1, 60);
            $roundTrip = Cache::get('public-feedback:doctor') === 1;
            $lock = Cache::lock('public-feedback:doctor:lock', 5);
            $locked = $lock->get();
            if ($locked) {
                $lock->release();
            }
            $this->add("Cache ({$cacheStore})", $roundTrip && $locked ? 'OK' : 'FAIL', $roundTrip && $locked ? 'Read, write and atomic locks work (one-time form tokens need them)' : 'Cache store cannot '.($roundTrip ? 'take a lock' : 'store a value').': the one-time form token fails and every submission is refused');
        } catch (Throwable $e) {
            $this->add("Cache ({$cacheStore})", 'FAIL', 'Cache error: '.$e->getMessage());
        }

        $sessionDriver = (string) config('session.driver');
        $sessionOk = $sessionDriver !== 'database' || Schema::hasTable((string) config('session.table', 'sessions'));
        $this->add("Session ({$sessionDriver})", $sessionOk ? 'OK' : 'FAIL', $sessionOk ? 'Store available' : 'The sessions table is missing: every form answers 419 (page expired)');

        $queue = (string) config('queue.default');
        if ($queue === 'sync') {
            $this->add("Queue ({$queue})", 'WARN', 'Notifications are sent during the request; a mail or SMS failure is logged, the submission is kept');
        } elseif ($queue === 'database') {
            if (! Schema::hasTable('jobs')) {
                $this->add("Queue ({$queue})", 'FAIL', 'The jobs table is missing');
            } else {
                $pending = DB::table('jobs')->count();
                $oldest = DB::table('jobs')->min('created_at');
                $failed = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0;
                $stale = $oldest !== null && (int) $oldest < now()->subMinutes(15)->getTimestamp();
                $this->add("Queue ({$queue})", $stale ? 'WARN' : 'OK', sprintf('%d waiting, %d failed%s', $pending, $failed, $stale ? '; the oldest waits over 15 minutes: no worker is running (php artisan queue:work database --queue=notifications,default)' : ''));
            }
        } else {
            $this->add("Queue ({$queue})", 'OK', 'Make sure a worker runs for the "notifications" queue');
        }

        $this->add('Mail', (string) config('mail.default') === 'log' ? 'WARN' : 'OK', 'mailer '.config('mail.default').((string) config('mail.default') === 'log' ? ': acknowledgements and alerts only go to the log' : ''));
    }

    private function checkWeb(): void
    {
        $url = (string) config('app.url');
        $https = str_starts_with($url, 'https://');
        $secure = config('session.secure');
        $this->add('APP_URL', $url === '' || str_contains($url, 'localhost') ? 'WARN' : 'OK', $url === '' ? 'empty' : $url.' (printed QR codes and e-mailed links use it)');
        $this->add('Secure session cookie', ($secure === true && ! $https) ? 'FAIL' : 'OK', ($secure === true && ! $https) ? 'SESSION_SECURE_COOKIE=true but APP_URL is not https: browsers drop the cookie and every submission answers 419' : 'SESSION_SECURE_COOKIE='.var_export($secure, true).', APP_URL '.($https ? 'https' : 'http'));
        $domain = config('session.domain');
        $this->add('Session cookie domain', $domain === null || $domain === '' ? 'OK' : 'WARN', $domain === null || $domain === '' ? 'default (current host)' : "set to {$domain}: must match the public host or cookies are dropped");
        $proxies = config('trustedproxy.proxies');
        $this->add('Trusted proxies', 'OK', $proxies === null ? 'none (fine when PHP sees the visitor\'s own address; behind a proxy every visitor would share one rate limit)' : (is_array($proxies) ? implode(', ', $proxies) : (string) $proxies));
        $this->add('Environment', config('app.debug') ? 'WARN' : 'OK', config('app.env').(config('app.debug') ? ', APP_DEBUG=true (turn off on a live server)' : ''));
    }

    private function checkLog(): void
    {
        $log = storage_path('logs/laravel.log');
        if (! is_file($log)) {
            $this->add('Recent submission failures', 'OK', 'No log file');

            return;
        }
        $lines = [];
        $handle = fopen($log, 'rb');
        if ($handle === false) {
            $this->add('Recent submission failures', 'WARN', 'Log file is not readable');

            return;
        }
        // Only the tail of a large log is scanned.
        $size = filesize($log) ?: 0;
        fseek($handle, max(0, $size - 2_000_000));
        while (($line = fgets($handle)) !== false) {
            if (str_contains($line, 'Public feedback submission failed')) {
                $lines[] = trim(mb_strimwidth($line, 0, 200, '…'));
            }
        }
        fclose($handle);
        $recent = array_slice($lines, -3);
        $this->add('Recent submission failures', $recent === [] ? 'OK' : 'FAIL', $recent === [] ? 'None in the last 2 MB of the log' : count($lines).' found; last: '.end($recent));
    }

    private function add(string $check, string $status, string $detail): void
    {
        $this->rows[] = [$check, $status, $detail];
    }
}

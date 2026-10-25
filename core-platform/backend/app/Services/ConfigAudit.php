<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * The production configuration review (ADR 0011): each setting the Core
 * relies on, read from the running configuration (never a secret's value),
 * with pass, warning or fail and what to do. core:config-audit prints it and
 * fails in production when anything fails; System Health shows it.
 *
 * In production (ADR 0012) the separate keys, the off-server seal heads, the
 * backup status, a running scheduler and private storage are required, not
 * advised: each fails there and only warns elsewhere. Keys must also be
 * independent of APP_KEY, of the database password and of each other.
 */
final class ConfigAudit
{
    /** @return list<array{key: string, status: string, value: string, advice: string}> */
    public function results(): array
    {
        $env = (string) app()->environment();
        $url = (string) config('app.url');
        $origins = (array) config('core.frontend_origins');
        $mail = (string) config('mail.default');
        $emailOn = (bool) app(CoreSettings::class)->get('notifications.email_enabled');
        $production = $env === 'production';
        $credentials = new CredentialKeys;
        $sealKey = (string) config('core.audit_seal_key');
        $nationalKey = (string) config('core.national_id_hash_key');
        $headDisk = (string) config('core.audit_head_disk');
        $backup = app(BackupStatus::class)->check();
        // The scheduler's heartbeat: its most recent run of any task. Every task's own lateness is System Health's
        // concern; requiring them all here would refuse every release that adds a task (ADR 0012).
        $lastRun = collect(array_keys(SystemHealth::SCHEDULED))->map(fn (string $c): ?string => Cache::get('core.scheduler.'.$c))->filter()->max();
        $heartbeat = $lastRun === null ? null : (int) now()->diffInMinutes($lastRun, true);
        $shared = $this->sharedKeys($credentials, $sealKey, $nationalKey);
        $exposed = $this->exposedDisks();
        $check = fn (string $key, bool $ok, string $value, string $advice, bool $warnOnly = false): array => [
            'key' => $key, 'status' => $ok ? 'pass' : ($warnOnly ? 'warning' : 'fail'), 'value' => $value, 'advice' => $ok ? '' : $advice,
        ];

        return [
            $check('APP_ENV', $env === 'production', $env, 'Set APP_ENV=production on the live server.', true),
            $check('APP_DEBUG', ! (bool) config('app.debug'), config('app.debug') ? 'true' : 'false', 'Set APP_DEBUG=false: debug pages reveal internals.'),
            $check('APP_KEY', (string) config('app.key') !== '', config('app.key') ? 'set' : 'missing', 'Generate an application key (php artisan key:generate).'),
            $check('APP_URL', str_starts_with($url, 'https://'), $url === '' ? '(empty)' : (str_starts_with($url, 'https://') ? 'https' : 'http'), 'Serve the Core over HTTPS and set APP_URL to the https address.'),
            $check('SESSION_SECURE_COOKIE', (bool) config('session.secure'), config('session.secure') ? 'true' : 'false', 'Set SESSION_SECURE_COOKIE=true so the session cookie travels over HTTPS only.'),
            $check('SESSION_SAME_SITE', in_array(config('session.same_site'), ['lax', 'strict'], true), (string) (config('session.same_site') ?? 'none'), 'Use SESSION_SAME_SITE=lax or strict.'),
            $check('SESSION_HTTP_ONLY', (bool) config('session.http_only'), config('session.http_only') ? 'true' : 'false', 'Keep the session cookie HTTP-only.'),
            $check('SESSION_DRIVER', in_array(config('session.driver'), ['database', 'redis'], true), (string) config('session.driver'), 'Use the database or redis session driver so sessions can be ended per account.'),
            $check('SESSION_ENCRYPT', (bool) config('session.encrypt'), config('session.encrypt') ? 'true' : 'false', 'Encrypt session data (SESSION_ENCRYPT=true).', true),
            $check('QUEUE_CONNECTION', ! in_array(config('queue.default'), ['sync', 'null'], true), (string) config('queue.default'),
                'Use a real queue (database or redis) with a worker: exports, notifications, imports and webhooks run there.'),
            $check('CACHE_STORE', ! in_array(config('cache.default'), ['array', 'null'], true), (string) config('cache.default'), 'Use a shared cache store (database or redis).'),
            $check('MAIL_MAILER', ! $emailOn || ! in_array($mail, ['log', 'array'], true), $mail, 'E-mail notifications are on but mail only goes to the log: configure a mailer or turn them off.'),
            $check('LOG_LEVEL', ! in_array(config('logging.channels.'.config('logging.default').'.level', 'debug'), ['debug'], true),
                (string) config('logging.channels.'.config('logging.default').'.level', 'debug'), 'Log at info or above in production.', true),
            $check('CORE_FRONTEND_ORIGINS', $origins !== [] && array_filter($origins, fn (string $o): bool => ! str_starts_with($o, 'https://')) === [],
                implode(',', array_map(fn (string $o): string => parse_url($o, PHP_URL_SCHEME).'://…', $origins)), 'Allow only https origins for browser API calls.', true),
            // Read from the configuration, not env(): with the configuration cached, env() is empty (ADR 0012).
            $check('TRUSTED_PROXIES', (string) config('trustedproxy.proxies') !== '', (string) config('trustedproxy.proxies') === '' ? '(none)' : 'set',
                'Behind a load balancer, set TRUSTED_PROXIES so client addresses and HTTPS are seen correctly.', true),
            $check('CORE_MFA_ENFORCE', (bool) config('core.mfa_enforce'), config('core.mfa_enforce') ? 'true' : 'false', 'Keep two-step sign-in enforced (CORE_MFA_ENFORCE=true).'),
            $check('CORE_CREDENTIAL_KEYS', ! $credentials->derived(), $credentials->derived() ? 'derived from APP_KEY' : 'configured (version '.$credentials->current().')',
                'Give card credentials their own random key of 32 bytes or more (CORE_CREDENTIAL_KEYS="1:base64:…"), apart from APP_KEY.', ! $production),
            $check('CORE_AUDIT_SEAL_KEY', strlen($this->keyBytes($sealKey)) >= 32, $sealKey === '' ? 'derived from APP_KEY' : (strlen($this->keyBytes($sealKey)) >= 32 ? 'configured' : 'too short'),
                'Give audit seals their own random key of 32 bytes or more (CORE_AUDIT_SEAL_KEY), apart from APP_KEY.', ! $production),
            $check('NATIONAL_ID_HASH_KEY', $nationalKey !== '', $nationalKey === '' ? 'derived from APP_KEY' : 'configured',
                'Set NATIONAL_ID_HASH_KEY to the EUISIS key, so imported national ID hashes match and do not depend on APP_KEY.', ! $production),
            $check('KEY_INDEPENDENCE', $shared === [], $shared === [] ? 'independent' : 'shared: '.implode(', ', $shared),
                'Every secret must be its own random value: never APP_KEY, the database password or another Core key.'),
            $check('CORE_AUDIT_HEAD_DISK', $headDisk !== '' && config("filesystems.disks.{$headDisk}") !== null,
                $headDisk === '' ? '(not shipped)' : $headDisk.' ('.(string) config("filesystems.disks.{$headDisk}.driver", 'unknown disk').')',
                'Copy seal heads off the database server: set CORE_AUDIT_HEAD_DISK to a disk on another host or an object-locked bucket.', ! $production),
            $check('CORE_BACKUP_STATUS_FILE', (string) config('core.backup_status_file') !== '', (string) config('core.backup_status_file') === '' ? '(not integrated)' : 'configured',
                'Have the backup tooling write its status file and point CORE_BACKUP_STATUS_FILE at it.', ! $production),
            $check('BACKUP_STATUS', $backup['status'] === 'healthy', $backup['status'],
                'The last backup must have succeeded within CORE_BACKUP_MAX_AGE_HOURS and its restore test passed (System Health › Backups).', ! $production || $backup['status'] === 'warning'),
            $check('SCHEDULER', $heartbeat !== null && $heartbeat <= 10, $heartbeat === null ? 'never ran' : "last task {$heartbeat} min ago",
                'Run the scheduler every minute (schedule:run from cron or schedule:work under a supervisor).', ! $production),
            $check('PRIVATE_STORAGE', $exposed === [], $exposed === [] ? 'private' : 'exposed: '.implode(', ', $exposed),
                'Evidence, imports, exports and seal heads must stay on private disks outside the public web root.'),
            $check('CORE_MONITOR_TOKEN', strlen((string) config('core.monitor_token')) >= 32, (string) config('core.monitor_token') === '' ? '(not set)' : (strlen((string) config('core.monitor_token')) >= 32 ? 'set' : 'too short'),
                'Give the external monitor a random token of 32 characters or more (CORE_MONITOR_TOKEN) for GET /health/monitor.', true),
        ];
    }

    /** @return list<string> the pairs of secrets that hold the same value (names only) */
    private function sharedKeys(CredentialKeys $credentials, string $sealKey, string $nationalKey): array
    {
        $secrets = ['APP_KEY' => (string) config('app.key'), 'DB_PASSWORD' => (string) config('database.connections.'.config('database.default').'.password'),
            'CORE_AUDIT_SEAL_KEY' => $sealKey, 'NATIONAL_ID_HASH_KEY' => $nationalKey, 'CORE_MONITOR_TOKEN' => (string) config('core.monitor_token')];
        if (! $credentials->derived()) {
            foreach ($credentials->materials() as $i => $key) {
                $secrets['CORE_CREDENTIAL_KEYS#'.($i + 1)] = $key;
            }
        }
        $values = [];
        foreach (array_filter($secrets, fn (string $value): bool => $value !== '') as $name => $value) {
            $values[$name] = array_unique([$value, $this->keyBytes($value)]);
        }
        $shared = [];
        $names = array_keys($values);
        foreach ($names as $i => $a) {
            foreach (array_slice($names, $i + 1) as $b) {
                if (array_intersect($values[$a], $values[$b]) !== []) {
                    $shared[] = "{$a}={$b}";
                }
            }
        }

        return $shared;
    }

    /** @return list<string> the private disks that are public or inside the web root */
    private function exposedDisks(): array
    {
        $public = str_replace('\\', '/', public_path());
        $exposed = [];
        foreach (['local', 'evidence', 'imports', 'exports', 'audit_heads'] as $name) {
            $disk = (array) config("filesystems.disks.{$name}", []);
            $root = str_replace('\\', '/', (string) ($disk['root'] ?? ''));
            if (($disk['visibility'] ?? 'private') === 'public' || (($disk['driver'] ?? '') === 'local' && $root !== '' && str_starts_with($root, $public))) {
                $exposed[] = $name;
            }
        }

        return $exposed;
    }

    private function keyBytes(string $key): string
    {
        return str_starts_with($key, 'base64:') ? (string) base64_decode(substr($key, 7), true) : $key;
    }
}

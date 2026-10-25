<?php

declare(strict_types=1);

namespace App\Services;

/**
 * The production configuration review (ADR 0011): each setting the Core
 * relies on, read from the running configuration (never a secret's value),
 * with pass, warning or fail and what to do. core:config-audit prints it and
 * fails in production when anything fails; System Health shows it.
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
            $check('TRUSTED_PROXIES', (string) env('TRUSTED_PROXIES', '') !== '', (string) env('TRUSTED_PROXIES', '') === '' ? '(none)' : 'set',
                'Behind a load balancer, set TRUSTED_PROXIES so client addresses and HTTPS are seen correctly.', true),
            $check('CORE_MFA_ENFORCE', (bool) config('core.mfa_enforce'), config('core.mfa_enforce') ? 'true' : 'false', 'Keep two-step sign-in enforced (CORE_MFA_ENFORCE=true).'),
            $check('CORE_CREDENTIAL_KEYS', (string) config('core.credential_keys') !== '', (string) config('core.credential_keys') === '' ? 'derived from APP_KEY' : 'configured',
                'Give card credentials their own key (CORE_CREDENTIAL_KEYS), apart from APP_KEY.', true),
            $check('CORE_AUDIT_SEAL_KEY', (string) config('core.audit_seal_key') !== '', (string) config('core.audit_seal_key') === '' ? 'derived from APP_KEY' : 'configured',
                'Give audit seals their own key (CORE_AUDIT_SEAL_KEY), kept apart from APP_KEY.', true),
            $check('CORE_BACKUP_STATUS_FILE', (string) config('core.backup_status_file') !== '', (string) config('core.backup_status_file') === '' ? '(not integrated)' : 'configured',
                'Have the backup tooling write its status file and point CORE_BACKUP_STATUS_FILE at it.', true),
        ];
    }
}

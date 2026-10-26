<?php

declare(strict_types=1);

namespace App\Services;

use App\Actions\SaveUser;
use App\Models\ApiClient;
use App\Models\ApiRequestLog;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Support\CorePermissions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Operational health for city-wide administrators, from real measurements
 * only: each check is ok, warning, error or unknown, with its figures. What the
 * Core cannot see (backups, disk capacity of the host) says so instead of
 * claiming to be healthy. No secret or connection detail is returned.
 */
final class SystemHealth
{
    /** The scheduled commands whose last run is recorded (core.scheduler.<name>), with the age that is still fine. */
    public const SCHEDULED = ['core:deliver-webhooks' => 10, 'core:expire-cards' => 60 * 26, 'core:audit-seal' => 15, 'core:audit-ship-heads' => 15, 'core:audit-verify' => 60 * 26,
        'core:break-glass-expire' => 10, 'core:notify-expiring' => 15, 'core:data-quality-scan' => 130, 'core:prune-integration-data' => 60 * 26, 'core:prune-exports' => 60 * 26];

    public static function recordRun(string $command): void
    {
        Cache::forever('core.scheduler.'.$command, now()->toIso8601String());
    }

    /** @return array<string, array{status: string, details: array<string, mixed>}> */
    public function checks(): array
    {
        return [
            'application' => $this->application(),
            'database' => $this->measure(function (): array {
                $started = hrtime(true);
                DB::select('select 1');

                return ['driver' => DB::getDriverName(), 'latency_ms' => (int) ((hrtime(true) - $started) / 1_000_000)];
            }),
            'cache' => $this->measure(function (): array {
                $key = 'core.health.'.Str::random(8);
                Cache::put($key, 'ok', 30);
                $ok = Cache::pull($key) === 'ok';

                return ['store' => config('cache.default'), 'round_trip' => $ok] + ($ok ? [] : ['status' => 'error']);
            }),
            'storage' => $this->measure(function (): array {
                $path = 'health/'.Str::random(8).'.txt';
                Storage::disk('local')->put($path, 'ok');
                $ok = Storage::disk('local')->get($path) === 'ok';
                Storage::disk('local')->delete($path);

                return ['disk' => 'local', 'writable' => $ok] + ($ok ? [] : ['status' => 'error']);
            }),
            'queue' => $this->queue(),
            'scheduler' => $this->scheduler(),
            'webhooks' => $this->webhooks(),
            'api' => $this->api(),
            'access' => $this->access(),
            'audit_integrity' => $this->auditIntegrity(),
            'backup' => $this->backup(),
        ];
    }

    /** The backup tooling's own report (BackupStatus); unknown when it is not integrated, never assumed healthy. */
    private function backup(): array
    {
        $result = app(BackupStatus::class)->check();
        $result['status'] = ['healthy' => 'ok', 'critical' => 'error'][$result['status']] ?? $result['status'];

        return $result;
    }

    /** @return array{status: string, details: array<string, mixed>} */
    private function auditIntegrity(): array
    {
        $status = app(AuditChain::class)->status();
        $last = $status['last_verification'];
        $offServer = app(AuditHeads::class)->configured();
        $shipFailedAt = Cache::get('core.audit_heads.failed_at');

        return [
            'status' => match (true) {
                $last !== null && $last->finished_at !== null && ! $last->ok => 'error',
                $shipFailedAt !== null => 'error',
                ! $offServer && app()->isProduction() => 'warning',
                $last === null || $last->finished_at === null => 'unknown',
                $status['unsealed'] > app(CoreSettings::class)->int('audit.unsealed_warning_rows')
                    || now()->diffInHours($last->finished_at, true) > app(CoreSettings::class)->int('audit.verify_max_age_hours') => 'warning',
                default => 'ok',
            },
            'details' => ['sealed_through' => $status['sealed_through'], 'last_sealed_at' => $status['last_sealed_at'], 'unsealed' => $status['unsealed'],
                'last_verified_at' => $last?->finished_at, 'last_verification_ok' => $last === null ? null : (bool) $last->ok,
                'heads_off_server' => $offServer, 'heads_shipped_through' => Cache::get('core.audit_heads.shipped_through'), 'heads_ship_failed_at' => $shipFailedAt],
        ];
    }

    /** @return array{status: string, details: array<string, mixed>} */
    private function application(): array
    {
        $production = app()->isProduction();
        $debug = (bool) config('app.debug');
        $https = str_starts_with((string) config('app.url'), 'https://');

        return [
            'status' => $production && ($debug || ! $https) ? 'error' : 'ok',
            'details' => ['environment' => app()->environment(), 'debug' => $debug, 'https_url' => $https, 'laravel' => app()->version(), 'php' => PHP_VERSION],
        ];
    }

    /** @return array{status: string, details: array<string, mixed>} */
    private function queue(): array
    {
        $failed = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : null;
        $database = config('queue.default') === 'database' && Schema::hasTable('jobs');
        $waiting = $database ? DB::table('jobs')->count() : null;
        // A job reserved for over 15 minutes is stuck (its worker died); one tried more than once is being retried.
        $stuck = $database ? DB::table('jobs')->whereNotNull('reserved_at')->where('reserved_at', '<', now()->subMinutes(15)->getTimestamp())->count() : null;
        $retrying = $database ? DB::table('jobs')->where('attempts', '>', 1)->count() : null;
        $byQueue = $database ? DB::table('jobs')->groupBy('queue')->selectRaw('queue, count(*) as total, min(created_at) as oldest')->get()
            ->mapWithKeys(fn ($r): array => [$r->queue => ['waiting' => (int) $r->total, 'oldest_age_minutes' => (int) floor((time() - (int) $r->oldest) / 60)]])->all() : null;
        $sync = in_array(config('queue.default'), ['sync', 'null'], true);

        // A job waiting long without being picked up means no worker serves its queue (ADR 0012: the rehearsal's
        // workers listened to "default" only and the notifications queue grew unseen).
        $oldestWaiting = $byQueue === null || $byQueue === [] ? 0 : max(array_column($byQueue, 'oldest_age_minutes'));

        return [
            'status' => match (true) {
                $stuck > 0 => 'error',
                $failed > 0 || $retrying > 0 || ($sync && app()->isProduction()) || $oldestWaiting > 15 => 'warning',
                default => 'ok',
            },
            'details' => ['connection' => config('queue.default'), 'failed_jobs' => $failed, 'waiting_jobs' => $waiting, 'stuck_jobs' => $stuck,
                'retrying_jobs' => $retrying, 'queues' => $byQueue],
        ];
    }

    /** @return array{status: string, details: array<string, mixed>} */
    public function scheduler(): array
    {
        $details = [];
        $status = 'ok';
        foreach (self::SCHEDULED as $command => $fineMinutes) {
            $last = Cache::get('core.scheduler.'.$command);
            $details[$command] = $last;
            if ($last === null) {
                $status = $status === 'ok' ? 'unknown' : $status;
            } elseif (now()->diffInMinutes($last, true) > $fineMinutes) {
                $status = 'warning';
            }
        }

        return ['status' => $status, 'details' => $details];
    }

    /** @return array{status: string, details: array<string, mixed>} */
    private function webhooks(): array
    {
        $pending = WebhookDelivery::query()->where('status', WebhookDelivery::PENDING);
        $oldest = (clone $pending)->min('created_at');
        $failed = WebhookDelivery::query()->where('status', WebhookDelivery::FAILED)->count();

        return [
            'status' => $failed > 0 ? 'warning' : 'ok',
            'details' => ['pending' => (clone $pending)->count(), 'failed' => $failed, 'oldest_pending' => $oldest],
        ];
    }

    /** @return array{status: string, details: array<string, mixed>} */
    private function api(): array
    {
        $day = ApiRequestLog::query()->where('created_at', '>=', now()->subDay());
        $calls = (clone $day)->count();
        $errors = (clone $day)->where('status', '>=', 500)->count();
        $refused = (clone $day)->whereBetween('status', [400, 499])->count();

        return [
            'status' => $calls > 0 && $errors / $calls > 0.05 ? 'warning' : 'ok',
            'details' => ['calls_24h' => $calls, 'server_errors_24h' => $errors, 'refused_24h' => $refused,
                'average_ms_24h' => (int) round((float) (clone $day)->avg('duration_ms'))],
        ];
    }

    /** @return array{status: string, details: array<string, mixed>} */
    private function access(): array
    {
        $cityAdmins = User::query()->whereIn('role', SaveUser::administratorRoles())->where('scope_type', User::SCOPE_CITYWIDE)->where('is_active', true)->count();
        $clients = ApiClient::query()->where('is_active', true)->whereNull('revoked_at');

        return [
            'status' => $cityAdmins === 0 || (app()->isProduction() && ! MfaPolicy::enforced()) ? 'error' : ($cityAdmins === 1 ? 'warning' : 'ok'),
            'details' => [
                'active_accounts' => User::query()->where('is_active', true)->count(),
                'city_wide_administrators' => $cityAdmins,
                'awaiting_password_change' => User::query()->where('is_active', true)->where('must_change_password', true)->count(),
                'live_sign_ins' => DB::table('personal_access_tokens')->where('tokenable_type', User::class)
                    ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->count(),
                'mfa_enforced' => MfaPolicy::enforced(),
                'privileged_without_mfa' => User::query()->where('is_active', true)->whereIn('role', CorePermissions::rolesWith('core.write'))
                    ->where(fn ($q) => $q->where('two_factor_enabled', false)->orWhereNull('two_factor_confirmed_at'))->count(),
                'active_api_clients' => (clone $clients)->count(),
                'api_clients_expiring_30_days' => (clone $clients)->whereNotNull('expires_at')->whereBetween('expires_at', [now(), now()->addDays(30)])->count(),
            ],
        ];
    }

    /**
     * @param  callable(): array<string, mixed>  $check
     * @return array{status: string, details: array<string, mixed>}
     */
    private function measure(callable $check): array
    {
        try {
            $details = $check();
            $status = $details['status'] ?? 'ok';
            unset($details['status']);

            return ['status' => $status, 'details' => $details];
        } catch (Throwable $exception) {
            report($exception);

            return ['status' => 'error', 'details' => ['error' => class_basename($exception)]];
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ApiClient;
use App\Models\ApiRequestLog;
use App\Models\User;
use App\Models\WebhookDelivery;
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
    public const SCHEDULED = ['core:deliver-webhooks' => 10, 'core:expire-cards' => 60 * 26];

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
            'backup' => ['status' => 'unknown', 'details' => ['note' => 'Backups are run outside the Core; their result is not reported here.']],
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
        $waiting = config('queue.default') === 'database' && Schema::hasTable('jobs') ? DB::table('jobs')->count() : null;

        return [
            'status' => $failed > 0 ? 'warning' : 'ok',
            'details' => ['connection' => config('queue.default'), 'failed_jobs' => $failed, 'waiting_jobs' => $waiting],
        ];
    }

    /** @return array{status: string, details: array<string, mixed>} */
    private function scheduler(): array
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
        $cityAdmins = User::query()->where('role', 'core_admin')->where('scope_type', User::SCOPE_CITYWIDE)->where('is_active', true)->count();
        $clients = ApiClient::query()->where('is_active', true)->whereNull('revoked_at');

        return [
            'status' => $cityAdmins === 0 ? 'error' : ($cityAdmins === 1 ? 'warning' : 'ok'),
            'details' => [
                'active_accounts' => User::query()->where('is_active', true)->count(),
                'city_wide_administrators' => $cityAdmins,
                'awaiting_password_change' => User::query()->where('is_active', true)->where('must_change_password', true)->count(),
                'live_sign_ins' => DB::table('personal_access_tokens')->where('tokenable_type', User::class)
                    ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->count(),
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

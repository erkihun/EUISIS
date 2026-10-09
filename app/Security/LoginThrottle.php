<?php

declare(strict_types=1);

namespace App\Security;

use App\Services\SystemSettings\SystemSettingsService;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Failed-login throttling shared by every sign-in form (admin/employee and
 * provider portals). Three temporary limits, never a permanent lock that an
 * attacker could use to keep someone out:
 *
 *   identifier + IP   Max Login Attempts per Lockout Minutes (System Settings;
 *                     default 5 per 15 min) — ordinary guessing
 *   identifier        4x that, from any IP — credential stuffing spread over
 *                     many addresses against one account
 *   IP                `security.login.per_ip_failures` per window — password
 *                     spraying (one password tried across many accounts)
 *
 * Only failures count. A success clears the identifier limits; the IP limit
 * is left to expire so one valid account cannot reset a spraying run.
 */
final class LoginThrottle
{
    public function __construct(private readonly SystemSettingsService $settings) {}

    public function ensureNotLocked(Request $request, string $guard, string $identifier, string $field): void
    {
        $seconds = 0;
        foreach ($this->limits($request, $guard, $identifier) as [$key, $max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                $seconds = max($seconds, RateLimiter::availableIn($key));
            }
        }

        if ($seconds === 0) {
            return;
        }

        event(new Lockout($request));

        throw ValidationException::withMessages([
            $field => trans('auth.throttle', ['seconds' => $seconds, 'minutes' => (int) ceil($seconds / 60)]),
        ]);
    }

    public function failed(Request $request, string $guard, string $identifier): void
    {
        foreach ($this->limits($request, $guard, $identifier) as [$key]) {
            RateLimiter::hit($key, $this->decaySeconds());
        }
    }

    public function succeeded(Request $request, string $guard, string $identifier): void
    {
        [$identity, $account] = $this->limits($request, $guard, $identifier);
        RateLimiter::clear($identity[0]);
        RateLimiter::clear($account[0]);
    }

    /** @return list<array{0: string, 1: int}> */
    private function limits(Request $request, string $guard, string $identifier): array
    {
        $normalized = Str::transliterate(Str::lower(trim($identifier)));
        $attempts = $this->maxAttempts();

        return [
            ["login:{$guard}:identity:{$normalized}|{$request->ip()}", $attempts],
            ["login:{$guard}:account:{$normalized}", $attempts * 4],
            ["login:{$guard}:ip:{$request->ip()}", max($attempts, (int) config('security.login.per_ip_failures', 100))],
        ];
    }

    private function maxAttempts(): int
    {
        return max(1, min(50, (int) $this->setting('max_login_attempts', 5)));
    }

    private function decaySeconds(): int
    {
        return max(1, min(1440, (int) $this->setting('lockout_minutes', 15))) * 60;
    }

    private function setting(string $key, int $default): mixed
    {
        try {
            return $this->settings->get('security', $key, $default) ?? $default;
        } catch (Throwable) {
            return $default;
        }
    }
}

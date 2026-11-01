<?php

declare(strict_types=1);

namespace Modules\Court\Application\Support;

use Modules\Court\Domain\Models\SystemSetting;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Failed-login lockout shared by every sign-in entry point.
 *
 * Two buckets are counted on each failure:
 *
 *  - client:  email + client IP. Trips after `login_max_attempts` failures and
 *             is the lockout a user normally meets.
 *  - account: email only. The client IP comes from proxy headers, which a caller
 *             can vary when the proxy chain is not pinned (TRUSTED_PROXIES), so
 *             this bucket caps guesses per account however the IP is presented.
 *             It allows ACCOUNT_MULTIPLIER times as many attempts, which makes
 *             deliberately locking out someone else's account costlier.
 */
final class LoginThrottle
{
    public const ACCOUNT_MULTIPLIER = 3;

    private function __construct(
        private readonly string $clientKey,
        private readonly string $accountKey,
        private readonly int $maxAttempts,
        private readonly int $decaySeconds,
    ) {}

    /**
     * @param  string  $scope  Separates portals/guards so one cannot lock another (e.g. "staff", "applicant", "api:admin").
     */
    public static function for(string $scope, string $email, ?string $ip): self
    {
        $settings = self::settings();
        $account = Str::transliterate(Str::lower(trim($email)));

        return new self(
            clientKey: "login:{$scope}:client|{$account}|".($ip ?: 'unknown-ip'),
            accountKey: "login:{$scope}:account|{$account}",
            maxAttempts: max(1, (int) ($settings->login_max_attempts ?? 5)),
            decaySeconds: max(60, (int) ($settings->lockout_minutes ?? 15) * 60),
        );
    }

    public function tooManyAttempts(): bool
    {
        return RateLimiter::tooManyAttempts($this->clientKey, $this->maxAttempts)
            || RateLimiter::tooManyAttempts($this->accountKey, $this->accountMaxAttempts());
    }

    /** Seconds until the caller may try again. */
    public function availableIn(): int
    {
        $wait = 0;

        if (RateLimiter::tooManyAttempts($this->clientKey, $this->maxAttempts)) {
            $wait = RateLimiter::availableIn($this->clientKey);
        }

        if (RateLimiter::tooManyAttempts($this->accountKey, $this->accountMaxAttempts())) {
            $wait = max($wait, RateLimiter::availableIn($this->accountKey));
        }

        return $wait;
    }

    public function hit(): void
    {
        RateLimiter::hit($this->clientKey, $this->decaySeconds);
        RateLimiter::hit($this->accountKey, $this->decaySeconds);
    }

    /** Reset both buckets after a successful sign-in. */
    public function clear(): void
    {
        RateLimiter::clear($this->clientKey);
        RateLimiter::clear($this->accountKey);
    }

    private function accountMaxAttempts(): int
    {
        return $this->maxAttempts * self::ACCOUNT_MULTIPLIER;
    }

    private static function settings(): object
    {
        try {
            $settings = SystemSetting::cached();
        } catch (\Throwable) {
            $settings = null;
        }

        return $settings ?? (object) ['login_max_attempts' => 5, 'lockout_minutes' => 15];
    }
}

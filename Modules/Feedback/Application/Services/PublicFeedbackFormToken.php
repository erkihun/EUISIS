<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Services;

use Illuminate\Support\Facades\Cache;

/**
 * One-time form tokens for the public forms (brief §39).
 *
 * The page receives a fresh token with every form; a submission that carries
 * one consumes it, so a replayed POST — the back button, a retried request,
 * a duplicated tab — is refused instead of stored twice.
 *
 * A token is self-describing and signed: 64 random bits, the minute it was
 * issued and a truncated HMAC under the application key, as 32 hex characters.
 * Nothing is stored when it is issued, so a cache clear (a deploy, a restart,
 * an eviction) never turns every open form into "already submitted"; only the
 * CONSUMED marker lives in the shared cache, so the once-only check still
 * holds across application nodes (brief §103). The marker is written with an
 * atomic add, which is what makes a second use of the same token lose.
 */
class PublicFeedbackFormToken
{
    private const TTL_MINUTES = 120;

    /** Hex characters of the random part, the issue minute and the signature: 16 + 8 + 8. */
    private const RANDOM_LENGTH = 16;

    private const TIME_LENGTH = 8;

    public function issue(): string
    {
        $payload = bin2hex(random_bytes(self::RANDOM_LENGTH / 2)).str_pad(dechex($this->minuteNow()), self::TIME_LENGTH, '0', STR_PAD_LEFT);

        return $payload.$this->signature($payload);
    }

    /** True exactly once per issued token; false for an unknown, forged, expired or already used one. */
    public function consume(string $token): bool
    {
        if (! $this->valid($token)) {
            return false;
        }

        return Cache::add($this->usedKey($token), 1, now()->addMinutes(self::TTL_MINUTES + 1));
    }

    /** Allow the same form to be retried when its submission did not commit. */
    public function restore(string $token): void
    {
        if ($this->valid($token)) {
            Cache::forget($this->usedKey($token));
        }
    }

    private function valid(string $token): bool
    {
        if (preg_match('/^[0-9a-f]{32}$/', $token) !== 1) {
            return false;
        }

        $payload = substr($token, 0, self::RANDOM_LENGTH + self::TIME_LENGTH);

        if (! hash_equals($this->signature($payload), substr($token, self::RANDOM_LENGTH + self::TIME_LENGTH))) {
            return false;
        }

        $issuedMinute = hexdec(substr($payload, self::RANDOM_LENGTH, self::TIME_LENGTH));
        $age = $this->minuteNow() - $issuedMinute;

        // A token from the future is forged or from a clock far off; a token older than the form's lifetime has expired.
        return $age >= -1 && $age <= self::TTL_MINUTES;
    }

    private function signature(string $payload): string
    {
        return substr(hash_hmac('sha256', 'public-feedback-form:'.$payload, $this->key()), 0, 32 - self::RANDOM_LENGTH - self::TIME_LENGTH);
    }

    private function key(): string
    {
        $key = (string) config('app.key');

        return str_starts_with($key, 'base64:') ? (string) base64_decode(substr($key, 7), true) : $key;
    }

    private function minuteNow(): int
    {
        return intdiv(now()->getTimestamp(), 60);
    }

    private function usedKey(string $token): string
    {
        return 'public-feedback:form-token-used:'.$token;
    }
}

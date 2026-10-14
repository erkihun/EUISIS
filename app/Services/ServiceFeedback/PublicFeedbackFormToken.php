<?php

declare(strict_types=1);

namespace App\Services\ServiceFeedback;

use Illuminate\Support\Facades\Cache;

/**
 * One-time form tokens for the public forms (brief §39).
 *
 * The page receives a fresh token with every form; a submission that carries
 * one consumes it, so a replayed POST — the back button, a retried request,
 * a duplicated tab — is refused instead of stored twice. Tokens live in the
 * shared cache so the check holds across application nodes (brief §103).
 */
class PublicFeedbackFormToken
{
    private const TTL_MINUTES = 120;

    public function issue(): string
    {
        $token = bin2hex(random_bytes(16));

        Cache::put($this->key($token), 1, now()->addMinutes(self::TTL_MINUTES));

        return $token;
    }

    /** True exactly once per issued token; false for an unknown, expired or already used one. */
    public function consume(string $token): bool
    {
        if (preg_match('/^[0-9a-f]{32}$/', $token) !== 1) {
            return false;
        }

        $key = $this->key($token);

        return Cache::lock($key.':lock', 5)->block(2, function () use ($key): bool {
            if (! Cache::has($key)) {
                return false;
            }

            Cache::forget($key);

            return true;
        });
    }

    /** Allow the same form to be retried when its submission did not commit. */
    public function restore(string $token): void
    {
        if (preg_match('/^[0-9a-f]{32}$/', $token) === 1) {
            Cache::put($this->key($token), 1, now()->addMinutes(self::TTL_MINUTES));
        }
    }

    private function key(string $token): string
    {
        return 'public-feedback:form-token:'.$token;
    }
}

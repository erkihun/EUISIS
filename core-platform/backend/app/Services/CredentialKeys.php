<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\CarbonImmutable;

/**
 * The numbered keys credential tokens are hashed with (ADR 0010).
 *
 * CORE_CREDENTIAL_KEYS lists them newest first, comma-separated, each as
 * "version:key" with an optional "@YYYY-MM-DD" after which an older key is no
 * longer accepted (its grace period). The first is the current key, used for
 * every new credential; core:credentials-rekey moves existing ones onto it.
 * Unset, one key (version 1) is derived from the application key.
 */
final class CredentialKeys
{
    /** @var array<int, array{key: string, accept_until: ?CarbonImmutable}> */
    private array $keys = [];

    private int $current;

    public function __construct()
    {
        foreach (array_filter(array_map('trim', explode(',', (string) config('core.credential_keys')))) as $entry) {
            [$version, $rest] = array_pad(explode(':', $entry, 2), 2, '');
            $until = null;
            if (preg_match('/^(.*)@(\d{4}-\d{2}-\d{2})$/', $rest, $match) === 1) {
                [$rest, $until] = [$match[1], CarbonImmutable::parse($match[2])->endOfDay()];
            }
            $key = str_starts_with($rest, 'base64:') ? (string) base64_decode(substr($rest, 7), true) : $rest;
            if (ctype_digit($version) && strlen($key) >= 32) {
                $this->keys[(int) $version] ??= ['key' => $key, 'accept_until' => $until];
                $this->current ??= (int) $version;
            }
        }
        if ($this->keys === []) {
            $this->keys[1] = ['key' => hash('sha256', 'core-credential|'.config('app.key'), true), 'accept_until' => null];
            $this->current = 1;
        }
    }

    public function current(): int
    {
        return $this->current;
    }

    /** @return list<int> */
    public function versions(): array
    {
        return array_keys($this->keys);
    }

    public function hash(string $token, ?int $version = null): string
    {
        return hash_hmac('sha256', $token, $this->keys[$version ?? $this->current]['key']);
    }

    /** @return array<int, string> the token's hash under every configured key, to find it whichever key it was issued under */
    public function hashes(string $token): array
    {
        return array_map(fn (array $key): string => hash_hmac('sha256', $token, $key['key']), $this->keys);
    }

    /** A credential under this key is still accepted: the current key, or an older one inside its grace period. */
    public function accepts(int $version): bool
    {
        if ($version === $this->current) {
            return true;
        }
        $key = $this->keys[$version] ?? null;

        return $key !== null && ($key['accept_until'] === null || $key['accept_until']->isFuture());
    }

    public function acceptUntil(int $version): ?CarbonImmutable
    {
        return $this->keys[$version]['accept_until'] ?? null;
    }
}

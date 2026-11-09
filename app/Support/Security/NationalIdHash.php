<?php

declare(strict_types=1);

namespace App\Support\Security;

/**
 * The lookup hash of a national ID (decision D-1): HMAC-SHA-256 under a
 * secret key. An unkeyed SHA-256 of a 16-digit number can be reversed by brute
 * force from a database copy alone; keyed, the hash reveals no more than the
 * encrypted national ID it sits beside.
 *
 * The key is NATIONAL_ID_HASH_KEY when set, otherwise derived from APP_KEY
 * with a fixed label — no extra secret to escrow, and APP_KEY already protects
 * the encrypted national ID. Changing either key changes every hash: run
 * `php artisan security:rehash-national-ids` afterwards (it decrypts each
 * national ID with APP_KEY and writes the new hash).
 */
final class NationalIdHash
{
    public static function of(?string $nationalId): ?string
    {
        if ($nationalId === null || $nationalId === '') {
            return null;
        }

        return hash_hmac('sha256', $nationalId, self::key());
    }

    private static function key(): string
    {
        $dedicated = (string) config('security.national_id_hash_key', '');
        if ($dedicated !== '') {
            return $dedicated;
        }

        $appKey = (string) config('app.key');
        if (str_starts_with($appKey, 'base64:')) {
            $appKey = (string) base64_decode(substr($appKey, 7), true);
        }

        return hash_hmac('sha256', 'euisis:national-id-hash:v1', $appKey, true);
    }
}

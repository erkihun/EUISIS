<?php

declare(strict_types=1);

namespace App\Services\Integration\Events;

/**
 * Opaque change-feed cursor.
 *
 * base64url(JSON {v, p, a}) + "." + HMAC — the position, bound to the
 * application it was issued to. A tampered, forged or borrowed cursor is
 * refused (400 invalid_cursor). The cursor is a bookmark, never an
 * authority: scope and organization filtering are applied on every read
 * regardless of what the cursor says.
 */
final class FeedCursor
{
    public static function encode(int $position, string $applicationId): string
    {
        $body = rtrim(strtr(base64_encode((string) json_encode(['v' => 1, 'p' => $position, 'a' => self::bind($applicationId)])), '+/', '-_'), '=');

        return $body.'.'.self::mac($body);
    }

    /** @return int|null position, or null when the cursor is invalid for this application */
    public static function decode(string $cursor, string $applicationId): ?int
    {
        $parts = explode('.', $cursor);
        if (count($parts) !== 2 || ! hash_equals(self::mac($parts[0]), $parts[1])) {
            return null;
        }

        $json = base64_decode(strtr($parts[0], '-_', '+/'), true);
        $data = is_string($json) ? json_decode($json, true) : null;

        if (! is_array($data) || ($data['v'] ?? null) !== 1 || ! is_int($data['p'] ?? null) || $data['p'] < 0
            || ! hash_equals(self::bind($applicationId), (string) ($data['a'] ?? ''))) {
            return null;
        }

        return $data['p'];
    }

    private static function mac(string $body): string
    {
        return substr(hash_hmac('sha256', 'feed-cursor|'.$body, (string) config('app.key')), 0, 32);
    }

    private static function bind(string $applicationId): string
    {
        return substr(hash('sha256', 'feed-app|'.$applicationId), 0, 16);
    }
}

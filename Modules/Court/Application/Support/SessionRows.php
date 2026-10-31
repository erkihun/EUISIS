<?php

declare(strict_types=1);

namespace Modules\Court\Application\Support;

/**
 * Rows of the shared `sessions` table, as the session pages show them. The
 * table serves the staff and the applicant/respondent guards, and user_id is
 * only unique per guard, so a row belongs to a portal by the guard UseGuard
 * stored in its payload. A row is addressed in the browser by a keyed hash,
 * never by its id: the id is what the session cookie carries.
 */
final class SessionRows
{
    /** Stable, non-reversible handle for a session row. */
    public static function publicKey(string $sessionId): string
    {
        return substr(hash_hmac('sha256', $sessionId, (string) config('app.key')), 0, 40);
    }

    /** The guard the session was used with ("web", "applicant", "respondent"), or null when unknown. */
    public static function guard(string $payload): ?string
    {
        $decoded = $payload === '' ? false : base64_decode($payload, true);
        $data = $decoded === false ? null : @unserialize($decoded, ['allowed_classes' => false]);
        $guard = is_array($data) ? ($data['_auth_guard'] ?? null) : null;

        return is_string($guard) ? $guard : null;
    }

    public static function isPortal(string $payload): bool
    {
        return in_array(self::guard($payload), ['applicant', 'respondent'], true);
    }
}

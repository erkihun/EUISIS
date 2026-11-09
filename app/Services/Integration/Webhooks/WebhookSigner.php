<?php

declare(strict_types=1);

namespace App\Services\Integration\Webhooks;

/**
 * Webhook signature, scheme v1 (docs/integration/webhook-security.md).
 *
 *   signed content   "{timestamp}.{event_id}.{raw request body}"
 *   algorithm        HMAC-SHA256 with the subscription secret, lowercase hex
 *   header           Addis-Signature: t={timestamp},v1={hex}[,v1={hex}]
 *
 * During a secret rotation the header carries one v1 per active secret;
 * a receiver accepts the request when ANY v1 matches its secret.
 */
final class WebhookSigner
{
    public const HEADER_SIGNATURE = 'Addis-Signature';

    public const HEADER_EVENT_ID = 'Addis-Event-Id';

    public const HEADER_TIMESTAMP = 'Addis-Timestamp';

    public const HEADER_EVENT_TYPE = 'Addis-Event-Type';

    public const HEADER_DELIVERY_ID = 'Addis-Delivery-Id';

    /** @param list<string> $secrets */
    public static function signatureHeader(array $secrets, int $timestamp, string $eventId, string $body): string
    {
        $parts = ['t='.$timestamp];
        foreach ($secrets as $secret) {
            $parts[] = 'v1='.self::sign($secret, $timestamp, $eventId, $body);
        }

        return implode(',', $parts);
    }

    public static function sign(string $secret, int $timestamp, string $eventId, string $body): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$eventId.'.'.$body, $secret);
    }

    /**
     * Reference verifier — what a partner should do. Rejects a missing or
     * malformed header, a timestamp outside the tolerance (replay), and any
     * signature that does not match in constant time.
     */
    public static function verify(string $secret, string $header, string $eventId, string $body, ?int $now = null, int $toleranceSeconds = 300): bool
    {
        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($key === 't' && ctype_digit($value)) {
                $timestamp = (int) $value;
            } elseif ($key === 'v1' && $value !== '') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || $signatures === []) {
            return false;
        }

        if (abs(($now ?? time()) - $timestamp) > $toleranceSeconds) {
            return false;
        }

        $expected = self::sign($secret, $timestamp, $eventId, $body);
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }
}

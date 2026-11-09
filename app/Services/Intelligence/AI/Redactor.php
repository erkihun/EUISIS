<?php

declare(strict_types=1);

namespace App\Services\Intelligence\AI;

/**
 * Centralised redaction before anything leaves the platform (brief §35–36,
 * §57): secrets by key, and secret-looking or personal values by pattern —
 * bearer tokens, API keys, e-mail addresses, phone numbers, 16-digit
 * identifiers, UUIDs stay (they are opaque) but long digit runs do not.
 * Applied to text and recursively to arrays.
 */
final class Redactor
{
    public const REDACTED_KEYS = [
        'password', 'password_confirmation', 'current_password', 'new_password', 'token', 'access_token', 'refresh_token', 'api_token',
        'authorization', 'secret', 'private_key', 'signature', 'qr_token', 'verification_token', 'card_token', 'national_id', 'national_id_hash',
        '_token', 'otp', 'otp_code', 'recovery_code', 'recovery_codes', 'two_factor_secret', 'two_factor_recovery_codes', 'client_secret', 'api_key',
        'remember_token', 'temporary_password', 'user_password', 'password_hash', 'reset_token', 'cookie', 'set-cookie', 'x-api-key',
        'phone', 'phone_number', 'client_contact', 'client_email', 'email', 'date_of_birth', 'salary',
    ];

    private const PATTERNS = [
        '/\bBearer\s+[A-Za-z0-9\-._~+\/]+=*/i' => 'Bearer [REDACTED]',
        '/\b(sk|pk|key|token|secret)[-_][A-Za-z0-9_]{12,}\b/i' => '[REDACTED_KEY]',
        '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/' => '[EMAIL]',
        '/(?<![\w-])(?:\+?251|0)9\d{8}\b/' => '[PHONE]',
        '/\b\d{16}\b/' => '[ID]',
        '/\b\d{4}[ -]\d{4}[ -]\d{4}[ -]\d{4}\b/' => '[ID]',
        '/(?i)(password|secret|token)\s*[:=]\s*\S+/' => '$1=[REDACTED]',
    ];

    public static function text(?string $text): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        return (string) preg_replace(array_keys(self::PATTERNS), array_values(self::PATTERNS), $text);
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public static function array(array $data): array
    {
        $keys = array_map('strtolower', self::REDACTED_KEYS);
        $out = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), $keys, true)) {
                $out[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $out[$key] = self::array($value);
            } elseif (is_string($value)) {
                $out[$key] = self::text($value);
            } else {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /** Hard cap on what one request may carry; cut on a line boundary with a marker. */
    public static function truncate(string $text, int $maxChars): string
    {
        if (mb_strlen($text) <= $maxChars) {
            return $text;
        }
        $cut = mb_substr($text, 0, max(0, $maxChars - 40));
        $lastBreak = mb_strrpos($cut, "\n");
        if ($lastBreak !== false && $lastBreak > $maxChars / 2) {
            $cut = mb_substr($cut, 0, $lastBreak);
        }

        return $cut."\n[… truncated to the configured input limit]";
    }
}

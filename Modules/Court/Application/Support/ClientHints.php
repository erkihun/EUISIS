<?php

declare(strict_types=1);

namespace Modules\Court\Application\Support;

use Illuminate\Http\Request;

/**
 * What a browser says about itself for the Devices tab: the User-Agent
 * Client Hints (CaptureClientHints keeps them in the session) give an exact
 * OS and browser version; the User-Agent string is the fallback.
 *
 * @phpstan-type Hints array{platform?: string, platform_version?: string, brands?: string}
 */
final class ClientHints
{
    /** @return Hints|null */
    public static function fromRequest(Request $request): ?array
    {
        if (! $request->hasHeader('Sec-CH-UA-Platform')) {
            return null;
        }

        return array_filter([
            'platform' => trim((string) $request->header('Sec-CH-UA-Platform'), '" '),
            'platform_version' => trim((string) $request->header('Sec-CH-UA-Platform-Version'), '" '),
            'brands' => (string) ($request->header('Sec-CH-UA-Full-Version-List') ?: $request->header('Sec-CH-UA')),
        ]);
    }

    /**
     * The hints CaptureClientHints stored in a session row's payload.
     *
     * @return Hints|null
     */
    public static function fromSessionPayload(string $payload): ?array
    {
        if ($payload === '') {
            return null;
        }

        $decoded = base64_decode($payload, true);
        if ($decoded === false) {
            return null;
        }

        $data = @unserialize($decoded, ['allowed_classes' => false]);

        return is_array($data) && is_array($data['client_hints'] ?? null) ? $data['client_hints'] : null;
    }

    /** @param Hints $hints */
    public static function os(array $hints): ?string
    {
        $platform = (string) ($hints['platform'] ?? '');
        if ($platform === '') {
            return null;
        }

        if ($platform === 'Windows') {
            // Sec-CH-UA-Platform-Version >= 13 means Windows 11.
            $major = (int) strtok((string) ($hints['platform_version'] ?? ''), '.');

            return match (true) {
                $major >= 13 => 'Windows 11',
                $major >= 1 => 'Windows 10',
                default => 'Windows',
            };
        }

        return $platform;
    }

    /** @param Hints $hints */
    public static function browser(array $hints): ?string
    {
        if (preg_match_all('/"([^"]+)";v="([^"]+)"/', (string) ($hints['brands'] ?? ''), $matches, PREG_SET_ORDER) === false) {
            return null;
        }

        $brands = [];
        foreach ($matches as $match) {
            if (stripos($match[1], 'brand') === false) {
                $brands[$match[1]] = $match[2];
            }
        }

        foreach (['Microsoft Edge', 'Opera', 'Brave', 'Vivaldi', 'Google Chrome', 'Chromium'] as $name) {
            if (isset($brands[$name])) {
                return ($name === 'Google Chrome' ? 'Chrome' : $name).' '.$brands[$name];
            }
        }

        $name = array_key_first($brands);

        return $name === null ? null : $name.' '.$brands[$name];
    }

    /** The operating system named by a User-Agent string. */
    public static function osFromUserAgent(string $userAgent): ?string
    {
        return match (true) {
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'iPhone') => 'iPhone',
            str_contains($userAgent, 'iPad') => 'iPad',
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'Mac') => 'macOS',
            str_contains($userAgent, 'CrOS') => 'ChromeOS',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => null,
        };
    }

    /** The browser and major version named by a User-Agent string. */
    public static function browserFromUserAgent(string $userAgent): ?string
    {
        $patterns = [
            '/Edg(?:A|iOS)?\/([0-9]+)/i' => 'Microsoft Edge',
            '/Firefox\/([0-9]+)/i' => 'Firefox',
            '/OPR\/([0-9]+)/i' => 'Opera',
            '/Chrome\/([0-9]+)/i' => 'Chrome',
            '/Version\/([0-9]+).*Safari/i' => 'Safari',
        ];

        foreach ($patterns as $pattern => $name) {
            if (preg_match($pattern, $userAgent, $match) === 1) {
                return trim($name.' '.($match[1] ?? ''));
            }
        }

        return null;
    }
}

<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Turns a browser's User-Agent string into the three facts a person
 * recognises their own device by: browser, operating system and whether it
 * is a phone, a tablet or a desktop. Deliberately small — no vendor database,
 * no version numbers — because the profile's device list only needs
 * "Chrome on Windows" and an icon. Unknown parts come back null so the UI
 * can translate the fallback.
 */
final class UserAgent
{
    public const DESKTOP = 'desktop';

    public const PHONE = 'phone';

    public const TABLET = 'tablet';

    public const UNKNOWN = 'unknown';

    /**
     * @return array{browser: ?string, os: ?string, device: string}
     */
    public static function summarize(?string $userAgent): array
    {
        $ua = trim((string) $userAgent);

        if ($ua === '') {
            return ['browser' => null, 'os' => null, 'device' => self::UNKNOWN];
        }

        $os = self::os($ua);

        return [
            'browser' => self::browser($ua),
            'os' => $os,
            'device' => self::device($ua, $os),
        ];
    }

    /** "Chrome on Windows", "Safari on iOS", "Firefox", or null when nothing is known. */
    public static function label(?string $userAgent): ?string
    {
        $parts = self::summarize($userAgent);

        if ($parts['browser'] !== null && $parts['os'] !== null) {
            return "{$parts['browser']} on {$parts['os']}";
        }

        return $parts['browser'] ?? $parts['os'];
    }

    private static function browser(string $ua): ?string
    {
        // Order matters: Chromium-based browsers also say "Chrome" and "Safari".
        $patterns = [
            'Edge' => '/\bEdg(?:e|A|iOS)?\/\d/',
            'Opera' => '/\b(?:OPR|Opera)\//',
            'Samsung Internet' => '/\bSamsungBrowser\//',
            'Firefox' => '/\b(?:Firefox|FxiOS)\//',
            'Chrome' => '/\b(?:Headless)?(?:Chrome|CriOS|Chromium)\//',
            'Safari' => '/\bVersion\/[\d.]+.*\bSafari\//',
        ];

        foreach ($patterns as $name => $pattern) {
            if (preg_match($pattern, $ua) === 1) {
                return $name;
            }
        }

        return null;
    }

    private static function os(string $ua): ?string
    {
        $patterns = [
            'Windows' => '/\bWindows\b/',
            'iOS' => '/\b(?:iPhone|iPad|iPod)\b/',
            'Android' => '/\bAndroid\b/',
            'macOS' => '/\bMac OS X\b|\bMacintosh\b/',
            'ChromeOS' => '/\bCrOS\b/',
            'Linux' => '/\bLinux\b|\bUbuntu\b|\bX11\b/',
        ];

        foreach ($patterns as $name => $pattern) {
            if (preg_match($pattern, $ua) === 1) {
                return $name;
            }
        }

        return null;
    }

    private static function device(string $ua, ?string $os): string
    {
        if (preg_match('/\biPad\b|\bTablet\b/i', $ua) === 1 || (str_contains($ua, 'Android') && ! str_contains($ua, 'Mobile'))) {
            return self::TABLET;
        }

        if (preg_match('/\bMobile\b|\biPhone\b|\biPod\b/', $ua) === 1) {
            return self::PHONE;
        }

        return $os === null ? self::UNKNOWN : self::DESKTOP;
    }
}

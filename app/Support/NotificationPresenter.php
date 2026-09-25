<?php

declare(strict_types=1);

namespace App\Support;

use App\Notifications\DailyActivityNotification;
use App\Notifications\EmployeePortalNotification;
use App\Notifications\PasswordSecurityNotification;
use App\Notifications\PerformanceNotification;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Shapes a stored notification for the bell, My Portal and the dashboard.
 *
 * Wording is rendered at read time in the reader's language for the modules
 * that store a kind; anything else falls back to the text saved when it was
 * sent. Links are limited to paths inside this application.
 */
final class NotificationPresenter
{
    private const RENDERERS = [
        'daily_activity' => DailyActivityNotification::class,
        'employee_portal' => EmployeePortalNotification::class,
        'account_security' => PasswordSecurityNotification::class,
        'performance' => PerformanceNotification::class,
    ];

    /** The UI language is chosen in the browser, so it arrives with the request. */
    public static function locale(Request $request): string
    {
        foreach ([$request->query('locale'), $request->header('X-Locale'), session('locale')] as $candidate) {
            if (is_string($candidate) && in_array($candidate, ['en', 'am'], true)) {
                return $candidate;
            }
        }

        return in_array(app()->getLocale(), ['en', 'am'], true) ? app()->getLocale() : 'en';
    }

    /** @return array{id: string, title: string, message: string, url: ?string, read: bool, created_at: ?string} */
    public static function present(DatabaseNotification $notification, string $locale): array
    {
        $data = is_array($notification->data) ? $notification->data : [];
        $renderer = self::RENDERERS[$data['module'] ?? ''] ?? null;
        $text = $renderer !== null && isset($data['kind'])
            ? $renderer::render($data, $locale)
            : ['title' => (string) ($data['title'] ?? ''), 'message' => (string) ($data['message'] ?? '')];

        return [
            'id' => $notification->id,
            'title' => $text['title'],
            'message' => $text['message'],
            'url' => self::safeUrl($data['url'] ?? null),
            'read' => $notification->read_at !== null,
            'created_at' => $notification->created_at?->toIso8601String(),
        ];
    }

    /** Only same-application paths; never an external site or a script URL. */
    public static function safeUrl(mixed $url): ?string
    {
        return is_string($url) && str_starts_with($url, '/')
            && ! str_starts_with($url, '//') && ! preg_match('/[\\\\\x00-\x20]/', $url)
            ? $url
            : null;
    }
}

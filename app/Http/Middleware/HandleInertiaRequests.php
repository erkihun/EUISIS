<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\CalendarSystem;
use App\Enums\TransferAnnouncementStatus;
use App\Models\TransferAnnouncement;
use App\Models\User;
use App\Services\Calendar\CalendarService;
use App\Services\SystemSettings\SystemSettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Middleware;
use Throwable;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        /** @var User|null $user */
        $user = auth('web')->user();
        $settings = $this->publicSettings();
        $defaultLocale = (string) ($settings['localization.default_locale'] ?? config('app.locale', 'en'));

        $locale = session('locale', $defaultLocale);
        $calendarMode = (string) ($settings['localization.calendar_system_mode'] ?? 'locale_based');
        $calendarSystem = $this->resolveCalendarSystem($locale, $calendarMode);

        return [
            ...parent::share($request),

            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'status' => $user->status,
                    'profile_photo_url' => $user->profilePhotoUrl(),
                    'initials' => $user->initials(),
                ] : null,
                'roles' => $user ? $user->getRoleNames()->toArray() : [],
                'permissions' => $user ? $user->getAllPermissions()->pluck('name')->toArray() : [],
                'isSuperAdmin' => $user?->hasRole('Super Admin') ?? false,
            ],

            // Populated by SetCafeteriaPortalContext middleware on portal routes.
            // Null on non-portal routes so the frontend can always type-check safely.
            'cafeteriaProviderAuth' => null,

            'locale' => $locale,
            'calendar' => [
                'system' => $calendarSystem->value,
                'mode' => $calendarMode,
            ],
            'settings' => $settings,
            'registration_enabled' => (bool) config('security.registration_enabled', false),
            'announcement_count' => $this->publishedAnnouncementCount(),
            'is_employee_user' => $user !== null && $this->resolveIsEmployeeUser($user),
            'flash' => [
                // Individual-key form (preferred) — set via session('success'), etc.
                'success' => session('success'),
                'error' => session('error'),
                'warning' => session('warning'),
                'info' => session('info'),
                // Single-message fallback — set via session()->flash('flash.message', …)
                'message' => session('flash.message'),
                'type' => session('flash.type'),
                // One-time API token, shown once immediately after generation.
                // Never persisted — only this single response carries it.
                'generated_token' => session('flash.generated_token'),
            ],
        ];
    }

    private function resolveCalendarSystem(string $locale, string $mode): CalendarSystem
    {
        return match ($mode) {
            'gregorian_only' => CalendarSystem::Gregorian,
            'ethiopian_only' => CalendarSystem::Ethiopian,
            default => app(CalendarService::class)->calendarSystemForLocale($locale),
        };
    }

    private function publicSettings(): array
    {
        try {
            return app(SystemSettingsService::class)->getPublicSettings();
        } catch (Throwable) {
            return [];
        }
    }

    private function resolveIsEmployeeUser(User $user): bool
    {
        try {
            return Cache::remember(
                "user_{$user->id}_is_employee",
                300, // 5 minutes
                fn (): bool => $user->employee()->exists(),
            );
        } catch (Throwable) {
            return false;
        }
    }

    private function publishedAnnouncementCount(): int
    {
        try {
            return Cache::remember(
                'published_transfer_announcement_count',
                60, // 1 minute
                fn (): int => TransferAnnouncement::where('status', TransferAnnouncementStatus::Published)->count(),
            );
        } catch (Throwable) {
            return 0;
        }
    }
}

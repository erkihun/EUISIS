<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Controllers\Web\MfaController;
use App\Models\User;
use App\Services\CoreNavigation;
use App\Services\CoreSettings;
use App\Services\SharedSettings;
use App\Support\CorePermissions;
use Illuminate\Http\Request;
use Inertia\Middleware;

/**
 * The props every page gets, in the shape of EUISIS HandleInertiaRequests so
 * the copied screens read them unchanged. The Core is always in the Core
 * workspace (`workspace`); EUISIS-only features (assistant, communication,
 * the employee portal) are switched off.
 */
class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        /** @var User|null $user */
        $user = $request->user();
        $shared = app(SharedSettings::class);
        $settings = $shared->all();
        $locale = (string) session('locale', $settings['localization.default_locale'] ?? 'en');

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'status' => $user->is_active ? 'active' : 'inactive',
                    'profile_photo_url' => null,
                    'initials' => $this->initials($user->name),
                ] : null,
                'roles' => $user ? [CorePermissions::roleName($user)] : [],
                'permissions' => CorePermissions::for($user),
                'isSuperAdmin' => false,
                'canViewDashboard' => $user !== null,
                'unrestrictedScope' => $user?->isCityWide() ?? false,
            ],
            'cafeteriaProviderAuth' => null,
            'features' => [],
            'assistant' => ['enabled' => false],
            'publicAssistant' => ['enabled' => false],
            'communication' => null,
            'workspace' => fn (): ?array => $user ? ['name' => 'core', 'nav' => app(CoreNavigation::class)->for($user)] : null,
            'locale' => $locale,
            'richText' => null,
            'textLocale' => fn (): string => app()->getLocale(),
            'calendar' => ['system' => $locale === 'am' ? 'ethiopian' : 'gregorian', 'mode' => 'locale_based'],
            'settings' => $settings,
            'branding' => $shared->branding(),
            'idCardTemplate' => null,
            'idCardTemplates' => null,
            'registration_enabled' => false,
            'announcement_count' => 0,
            'is_employee_user' => false,
            'has_employee_record' => false,
            'session_policy' => fn (): ?array => $user ? [
                'idle_timeout_seconds' => TrackSessionActivity::timeoutSeconds(),
                'warning_seconds' => 60,
                'heartbeat_seconds' => 60,
                'login_url' => route('login'),
                'logout_url' => route('logout'),
            ] : null,
            'flash' => [
                'success' => session('success'),
                'error' => session('error'),
                'warning' => session('warning'),
                'info' => session('info'),
                'message' => session('flash.message'),
                'type' => session('flash.type'),
                'generated_token' => session('flash.generated_token'),
                'temporary_password' => session('flash.temporary_password'),
                // Shown once on the MFA page right after enrolment; never kept in the session longer.
                'mfa_recovery_codes_once' => session(MfaController::SESSION_RECOVERY_CODES),
            ],
            'password_policy' => fn (): array => ['min_length' => app(CoreSettings::class)->int('security.password_min_length'), 'max_length' => 128, 'history_count' => app(CoreSettings::class)->int('security.password_history_count'), 'blocks_personal_information' => true, 'blocks_common_passwords' => false, 'checks_breaches' => false],
        ];
    }

    private function initials(string $name): string
    {
        $parts = preg_split('/\s+/u', trim($name)) ?: [];

        return mb_strtoupper(implode('', array_map(fn (string $part): string => mb_substr($part, 0, 1), array_slice($parts, 0, 2))));
    }
}

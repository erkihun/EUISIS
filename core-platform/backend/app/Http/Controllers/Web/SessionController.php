<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Middleware\TrackSessionActivity;
use App\Models\User;
use App\Services\AuditWriter;
use App\Services\CoreSettings;
use App\Support\CorePermissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Browser sign-in, sign-out, the idle-timeout endpoints and the profile page (EUISIS Auth/Login, Profile/Edit). */
class SessionController extends Controller
{
    public function __construct(private readonly CoreSettings $settings) {}

    public function create(Request $request): Response
    {
        return Inertia::render('Auth/Login', [
            'canResetPassword' => false,
            'status' => session('status'),
            'sessionNotice' => session('session_notice'),
            'entry' => 'core',
        ]);
    }

    public function store(Request $request, AuditWriter $audit): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        $key = 'core-login:'.strtolower($data['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, $this->settings->int('security.max_login_attempts'))) {
            $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);
            throw ValidationException::withMessages(['email' => __('auth.throttle', ['seconds' => $minutes * 60, 'minutes' => $minutes])]);
        }

        if (! Auth::guard('web')->attempt(['email' => $data['email'], 'password' => $data['password'], 'is_active' => true])) {
            RateLimiter::hit($key, $this->settings->int('security.lockout_minutes') * 60);
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();
        $request->session()->put(TrackSessionActivity::KEY, time());
        /** @var User $user */
        $user = $request->user();
        $audit->write('auth.signed_in', $user, $user, $user->scope_organization_id);

        return redirect()->intended(route('core.dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    /** "Exit Core": to the full EUISIS application when one is configured, otherwise sign out. */
    public function exitCore(Request $request): RedirectResponse|\Symfony\Component\HttpFoundation\Response
    {
        $euisis = config('core.euisis_url');
        if (is_string($euisis) && $euisis !== '') {
            return Inertia::location($euisis);
        }

        return $this->destroy($request);
    }

    public function status(Request $request): JsonResponse
    {
        return response()->json(['remaining_seconds' => TrackSessionActivity::remainingSeconds($request)]);
    }

    public function activity(Request $request): JsonResponse
    {
        $request->session()->put(TrackSessionActivity::KEY, time());

        return response()->json(['remaining_seconds' => TrackSessionActivity::remainingSeconds($request)]);
    }

    /** The header bell's feed (EUISIS NotificationBell). The Core sends no in-app notifications, so it is always empty. */
    public function notifications(Request $request): JsonResponse
    {
        return response()->json(['unread_count' => 0, 'items' => [], 'page' => 1, 'last_page' => 1]);
    }

    public function readNotifications(): JsonResponse
    {
        return response()->json(['unread_count' => 0]);
    }

    public function profile(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $sessions = DB::table('sessions')->where('user_id', $user->id)->orderByDesc('last_activity')->limit(20)->get();

        return Inertia::render('Profile/Edit', [
            'mustVerifyEmail' => false,
            'status' => session('status'),
            'profile' => [
                'name' => $user->name,
                'email' => $user->email,
                'phone_number' => null,
                'gender' => null,
                'national_id' => null,
                'profile_photo_url' => null,
                'initials' => mb_strtoupper(mb_substr($user->name, 0, 1)),
                'roles' => [CorePermissions::roleName($user)],
                'status' => $user->is_active ? 'active' : 'inactive',
                'last_login_at' => null,
                'created_at' => $user->created_at?->toIso8601String(),
            ],
            'security' => ['two_factor_enabled' => false, 'two_factor_required' => false, 'two_factor_confirmed_at' => null, 'two_factor_last_used_at' => null],
            'devices' => [
                'supported' => config('session.driver') === 'database',
                'sessions' => $sessions->map(fn (object $session): array => [
                    'id' => $session->id,
                    'ip_address' => $session->ip_address,
                    'user_agent' => $session->user_agent,
                    'browser' => null,
                    'platform' => null,
                    'device' => null,
                    'last_active_at' => date(DATE_ATOM, (int) $session->last_activity),
                    'is_current' => $session->id === $request->session()->getId(),
                ])->values(),
                'recent_sign_ins' => [],
            ],
        ]);
    }

    public function updateProfile(Request $request, AuditWriter $audit): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email,'.$user->id],
        ]);
        $old = $user->only(['name', 'email']);
        $user->update($data);
        $audit->write('user.profile_updated', $user, $user, $user->scope_organization_id, $old, $user->only(['name', 'email']));

        return redirect()->route('profile.edit')->with('flash', ['message' => __('Profile updated.'), 'type' => 'success']);
    }

    public function updatePassword(Request $request, AuditWriter $audit): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(12)],
        ]);
        $user->update(['password' => Hash::make($data['password'])]);
        $audit->write('user.password_changed', $user, $user, $user->scope_organization_id);

        return back()->with('flash', ['message' => __('Password updated.'), 'type' => 'success']);
    }

    public function destroyDevice(Request $request, string $session): RedirectResponse
    {
        DB::table('sessions')->where('user_id', $request->user()->id)->where('id', $session)->where('id', '!=', $request->session()->getId())->delete();

        return back();
    }

    public function destroyOtherDevices(Request $request): RedirectResponse
    {
        DB::table('sessions')->where('user_id', $request->user()->id)->where('id', '!=', $request->session()->getId())->delete();

        return back();
    }
}

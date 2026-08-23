<?php

declare(strict_types=1);

namespace CafeteriaSystem\Http\Controllers;

use CafeteriaSystem\Models\AuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Cafeteria-only authentication. Uses the `cafeteria` guard, never EUISIS. */
class AuthController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('Auth/Login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::guard('cafeteria')->attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        $user = Auth::guard('cafeteria')->user();

        if (! $user->isActive()) {
            Auth::guard('cafeteria')->logout();
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();

        AuditLog::query()->create([
            'cafeteria_user_id' => $user->getKey(),
            'event_type' => 'login',
            'ip_address' => $request->ip(),
            'occurred_at' => now(),
        ]);

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('cafeteria')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}

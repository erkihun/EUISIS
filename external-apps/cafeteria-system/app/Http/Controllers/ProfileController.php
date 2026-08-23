<?php

declare(strict_types=1);

namespace CafeteriaSystem\Http\Controllers;

use CafeteriaSystem\Models\AuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The signed-in operator's own account.
 *
 * Deliberately narrow: an operator may correct their display name and rotate
 * their own password. Role, cafeteria binding and provider are assigned by an
 * administrator and are shown read-only — self-service there would let a
 * scanner promote themselves.
 */
class ProfileController extends Controller
{
    public function show(Request $request): Response
    {
        $user = $request->user('cafeteria');

        return Inertia::render('Profile/Index', [
            'profile' => [
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'status' => $user->status,
                'provider' => $user->provider?->only(['name', 'code']),
                'cafeteria' => $user->cafeteria?->only(['name', 'code']),
                'last_login_at' => $user->last_login_at?->toDateTimeString(),
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user('cafeteria');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'email', 'max:255',
                Rule::unique('cafeteria_users', 'email')->ignore($user->getKey()),
            ],
        ]);

        $user->update($data);

        AuditLog::query()->create([
            'cafeteria_user_id' => $user->getKey(),
            'event_type' => 'profile_updated',
            'description' => 'Operator updated their own profile',
            'ip_address' => $request->ip(),
            'occurred_at' => now(),
        ]);

        return back()->with(['message' => __('cafeteria.profileUpdated'), 'type' => 'success']);
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $user = $request->user('cafeteria');

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        // Proving knowledge of the current password stops an unattended
        // terminal being used to lock the real operator out.
        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => __('cafeteria.currentPasswordIncorrect'),
            ]);
        }

        $user->update(['password' => $data['password']]);

        AuditLog::query()->create([
            'cafeteria_user_id' => $user->getKey(),
            'event_type' => 'password_changed',
            'description' => 'Operator changed their own password',
            'ip_address' => $request->ip(),
            'occurred_at' => now(),
        ]);

        return back()->with(['message' => __('cafeteria.passwordUpdated'), 'type' => 'success']);
    }
}

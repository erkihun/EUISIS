<?php

namespace Modules\Court\Http\Controllers\Admin;

use Modules\Court\Http\Controllers\Controller;
use Modules\Court\Http\Requests\Admin\UpdateProfileRequest;
use Modules\Court\Application\Services\Security\UserSessionRegistry;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class ProfileController extends Controller
{
    /**
     * The profile page (React, Profile/Edit from EUISIS): account, security
     * and devices tabs, with the props in the EUISIS shape.
     */
    public function edit(Request $request, UserSessionRegistry $sessions): Response
    {
        $user = $request->user();

        return Inertia::render('Profile/Edit', [
            'mustVerifyEmail' => $user instanceof MustVerifyEmail,
            'status' => session('status') ?? session('mfa_status'),
            'profile' => [
                'name' => $user->name,
                'email' => $user->email,
                'phone_number' => $user->phone,
                'gender' => $user->gender,
                'national_id' => $user->national_id,
                'profile_photo_url' => $user->avatar_url,
                'initials' => mb_strtoupper(mb_substr((string) $user->name, 0, 1)),
                'roles' => $user->roles()->pluck('name')->all(),
                'status' => (string) ($user->status ?? 'active'),
                'last_login_at' => null,
                'created_at' => $user->created_at?->toIso8601String(),
            ],
            'security' => [
                'two_factor_enabled' => $user->hasConfirmedMfa(),
                'two_factor_required' => $user->requiresMfa(),
                'two_factor_confirmed_at' => $user->mfa_confirmed_at?->toIso8601String(),
                'two_factor_last_used_at' => null,
            ],
            'devices' => [
                'supported' => $sessions->supported(),
                'sessions' => $sessions->forUser($user, $request),
                'recent_sign_ins' => [],
            ],
            // court-ms: the signature on letters and decisions; only an administrator changes it (users.manage).
            'signature' => [
                'url' => $user->signature_url,
                'read_only' => true,
            ],
        ]);
    }

    /**
     * Update the user's profile information and photo.
     */
    public function update(UpdateProfileRequest $request)
    {
        $user = $request->user();
        $validated = $request->safe()->except(['avatar', 'signature', 'remove_signature', 'profile_photo', 'phone_number']);
        $oldAvatarPath = $user->avatar_path;
        $newAvatarPath = $request->file('avatar')?->store('avatars', 'court_public');

        if ($newAvatarPath) {
            $validated['avatar_path'] = $newAvatarPath;
        }

        // Reset verification if email changes
        if (isset($validated['email']) && $validated['email'] !== $user->email) {
            $user->email_verified_at = null;
        }

        try {
            $user->fill($validated)->save();
        } catch (Throwable $exception) {
            Storage::disk('court_public')->delete(array_filter([$newAvatarPath]));
            throw $exception;
        }

        if ($newAvatarPath && $oldAvatarPath) {
            Storage::disk('court_public')->delete($oldAvatarPath);
        }

        return redirect()->route('court.profile.edit')->with('success', [
            'key' => 'court::messages.success.updated',
            'replace' => ['resource' => __('court::messages.resources.profile')],
        ]);
    }

    /**
     * Delete the user's account (Breeze style).
     */
    public function destroy(Request $request)
    {
        $request->validateWithBag('userDeletion', [
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        // Delete files before deleting the user
        if ($user->avatar_path) {
            Storage::disk('court_public')->delete($user->avatar_path);
        }
        if ($user->signature_path) {
            Storage::disk('court_public')->delete($user->signature_path);
        }

        // Logout via facade (more reliable across guards)
        Auth::logout();

        // Delete user
        $user->delete();

        // Kill session and CSRF
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/')->with('success', 'court::messages.success.account_deleted');
    }
}

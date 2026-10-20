<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Audit\WriteAuditLogAction;
use App\Actions\Users\UploadUserProfilePhotoAction;
use App\Enums\AuditEventType;
use App\Http\Requests\ProfileUpdateRequest;
use App\Models\User;
use App\Services\Security\UserSessionRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    public function edit(Request $request, UserSessionRegistry $sessions): Response|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        /*
         * One profile per person. An account linked to an employee record
         * uses My Profile, which holds both the employee details (with HR and
         * verification rules) and the account's sign-in settings. Editing the
         * same name, phone or National ID here too would give each fact two
         * sources of truth.
         */
        if ($user->employee !== null) {
            return redirect()->route('employee.profile');
        }

        return Inertia::render('Profile/Edit', [
            'mustVerifyEmail' => $user instanceof MustVerifyEmail,
            'status' => session('status'),
            'profile' => [
                'name' => $user->name,
                'email' => $user->email,
                'phone_number' => $user->phone_number,
                'gender' => $user->gender,
                'national_id' => $user->national_id,
                'profile_photo_url' => $user->profilePhotoUrl(),
                'initials' => $user->initials(),
                'roles' => $user->getRoleNames()->toArray(),
                'status' => $user->status,
                'last_login_at' => $user->last_login_at?->toDateTimeString(),
                'created_at' => $user->created_at?->toIso8601String(),
            ],
            // Security tab: the second factor's state. Setup and removal stay on the MFA routes.
            'security' => [
                'two_factor_enabled' => $user->hasMfaEnabled(),
                'two_factor_required' => $user->requiresMfa(),
                'two_factor_confirmed_at' => $user->two_factor_confirmed_at?->toIso8601String(),
                'two_factor_last_used_at' => $user->two_factor_last_used_at !== null
                    ? CarbonImmutable::parse($user->two_factor_last_used_at)->toIso8601String()
                    : null,
            ],
            // Devices tab: live sessions (database driver only) and the last sign-ins from the audit log.
            'devices' => [
                'supported' => $sessions->supported(),
                'sessions' => $sessions->forUser($user, $request->session()->getId()),
                'recent_sign_ins' => $sessions->recentSignIns($user),
            ],
        ]);
    }

    public function update(
        ProfileUpdateRequest $request,
        UploadUserProfilePhotoAction $photoAction,
        WriteAuditLogAction $writeAuditLogAction,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $validated = $request->validated();
        unset($validated['profile_photo']);

        $oldValues = $user->only(['name', 'email', 'phone_number', 'gender']);
        $oldValues['national_id_configured'] = $user->national_id !== null;

        $user->fill($validated);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        if ($request->hasFile('profile_photo')) {
            $path = $photoAction->execute($user, $request->file('profile_photo'), $user, $request, true);
            $user->update(['profile_photo_path' => $path]);
        }

        $fresh = $user->fresh();

        $writeAuditLogAction->execute(
            AuditEventType::ProfileUpdated,
            $user,
            $fresh,
            null,
            oldValues: $oldValues,
            newValues: [
                ...$fresh->only(['name', 'email', 'phone_number', 'gender']),
                'national_id_configured' => $fresh->national_id !== null,
            ],
            request: $request,
        );

        return Redirect::route('profile.edit')
            ->with('flash', ['message' => __('profile.updated_successfully'), 'type' => 'success']);
    }

    public function destroy(Request $request, WriteAuditLogAction $writeAuditLogAction): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $writeAuditLogAction->execute(
            AuditEventType::UserDeactivationBlockedSelf,
            $user,
            $user,
            null,
            newValues: ['reason' => 'profile_self_delete_blocked'],
            request: $request,
        );

        return Redirect::route('profile.edit')
            ->with('flash', ['message' => __('profile.self_delete_disabled'), 'type' => 'error']);
    }
}

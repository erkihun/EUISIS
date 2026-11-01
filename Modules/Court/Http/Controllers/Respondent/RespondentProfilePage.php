<?php

declare(strict_types=1);

namespace Modules\Court\Http\Controllers\Respondent;

use Modules\Court\Domain\Models\Applicant;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ViewErrorBag;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The respondent portal's profile (React, Court/Respondent/Profile): the account form, the password form and the
 * signed-in sessions of the applicant account the person acts with, on the respondent.profile.* routes.
 * Applicant\ApplicantProfileController::edit and Applicant\ApplicantSessionController::index hand their respondent
 * requests here; the actions behind the forms (update, password, revoking sessions) are theirs, unchanged.
 */
final class RespondentProfilePage
{
    /**
     * @param  Collection<int, object>|null  $sessions  ApplicantSessionController's list; given on the sessions route only.
     */
    public static function render(Request $request, ?Collection $sessions = null): Response
    {
        $user = $request->user('applicant');
        abort_unless($user instanceof Applicant, 401);

        $errors = $request->session()->get('errors');
        $securityError = $errors instanceof ViewErrorBag
            && ($errors->has('current_password') || $errors->has('password') || $errors->has('password_confirmation'));

        return Inertia::render('Court/Respondent/Profile', [
            'user' => [
                'first_name' => (string) $user->first_name,
                'middle_name' => (string) ($user->middle_name ?? ''),
                'last_name' => (string) $user->last_name,
                'gender' => $user->gender,
                'phone' => (string) ($user->phone ?? ''),
                // Presented as "0000 0000 0000 0000" by the model; stored as digits.
                'national_id_number' => (string) ($user->national_id_number ?? ''),
                'email' => (string) $user->email,
                'address' => (string) ($user->address ?? ''),
                'position' => (string) ($user->position ?? ''),
                'organization_name' => (string) ($user->organization_name ?? ''),
                'is_lawyer' => (bool) $user->is_lawyer,
                'lawyer_document_url' => ! empty($user->lawyer_document_path) && Route::has('court.applicant.profile.lawyer-document')
                    ? route('court.applicant.profile.lawyer-document')
                    : null,
            ],
            // Which tab opens: the sessions route shows the devices; a failed or saved password change, or the
            // sessions page's Security tab (?tab=security), security.
            'tab' => $sessions !== null
                ? 'devices'
                : ($securityError || $request->session()->has('security_success') || $request->query('tab') === 'security' ? 'security' : 'profile'),
            'sessions' => $sessions?->map(fn (object $session): array => [
                'id' => (string) $session->id,
                'ip_address' => $session->ip_address,
                'device_name' => (string) $session->device_name,
                'browser_name' => (string) $session->browser_name,
                'is_current' => (bool) $session->is_current,
                'last_active_at' => Carbon::parse($session->last_active_at)->toIso8601String(),
            ])->values()->all(),
            'sessionsSupported' => config('session.driver') === 'database',
            // Flashed under keys the shared flash does not carry.
            'status' => $request->session()->get('status'),
            'securitySuccess' => $request->session()->get('security_success'),
        ]);
    }
}

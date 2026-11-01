<?php

namespace Modules\Court\Http\Controllers\Applicant;

use Modules\Court\Http\Controllers\Controller;
use Modules\Court\Domain\Models\Respondent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;

class ApplicantProfileController extends Controller
{
    public function edit(Request $request)
    {
        if ($request->routeIs('court.respondent.profile.*')) {
            $request->session()->put('acting_as_respondent', true);

            // The respondent portal's profile is its own React page (Court/Respondent/Profile).
            return \Modules\Court\Http\Controllers\Respondent\RespondentProfilePage::render($request);
        }

        $user = $request->user('applicant');

        // The applicant's profile (React, Court/Applicant/Profile): account and security tabs, sessions beside them.
        return Inertia::render('Court/Applicant/Profile', [
            'profile' => [
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
                'has_lawyer_document' => ! empty($user->lawyer_document_path),
            ],
            // Flashed by updatePassword under a key the shared flash does not carry.
            'securitySuccess' => $request->session()->get('security_success'),
        ]);
    }

    public function update(Request $request)
    {
        $user = $request->user('applicant');
        $originalEmail = (string) $user->getRawOriginal('email');

        if (! $request->has('national_id_number') && $request->has('national_id')) {
            $request->merge(['national_id_number' => $request->input('national_id')]);
        }

        $request->merge([
            // Applicants store a digits-only National ID in the DB.
            'national_id_number' => preg_replace('/\D/', '', (string) $request->input('national_id_number', '')),
            // The applicants table now requires a non-null middle name, but an empty string is allowed.
            'middle_name' => (string) $request->input('middle_name', ''),
        ]);

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'gender' => ['required', Rule::in(['male', 'female'])],
            'position' => ['required', 'string', 'max:150'],
            'organization_name' => ['required', 'string', 'max:150'],
            'phone' => ['required', 'string', 'max:30', 'unique:court_applicants,phone,'.$user->id],
            'email' => ['required', 'email', 'max:255', 'unique:court_applicants,email,'.$user->id],
            'address' => ['required', 'string', 'max:255'],
            'national_id_number' => ['required', 'digits:16', 'unique:court_applicants,national_id_number,'.$user->id],

            'current_password' => ['nullable', 'current_password:applicant'],
            'password' => ['nullable', 'confirmed', Password::defaults()],

            'lawyer_document' => ['nullable', 'file', 'mimes:pdf', 'max:1024'],
        ], [
            'national_id_number.digits' => 'National ID must be exactly 16 digits.',
        ]);

        // Only lawyers may upload a credential document.
        if ($request->hasFile('lawyer_document') && $user->is_lawyer) {
            $newPath = app(\Modules\Court\Application\Services\SecureUploadService::class)->store(
                $request->file('lawyer_document'),
                'lawyer_documents',
                'court_private',
                ['related_type' => 'applicant', 'related_id' => (int) $user->id, 'applicant_id' => (int) $user->id]
            );

            if (! empty($user->lawyer_document_path)) {
                Storage::disk('court_private')->delete($user->lawyer_document_path);
            }

            $validated['lawyer_document_path'] = $newPath;
        }
        unset($validated['lawyer_document']);

        if (! empty($validated['password'])) {
            if (empty($validated['current_password'])) {
                return back()->withErrors(['current_password' => 'Enter your current password to set a new one.']);
            }
            $currentPassword = $validated['current_password'];
            $newPassword = $validated['password'];
            $validated['password'] = Hash::make($validated['password']);
        } else {
            unset($validated['password']);
        }

        unset($validated['current_password']);

        $user->fill($validated);
        if (! empty($currentPassword)) {
            $user->forceFill(['remember_token' => Str::random(60)]);
        }
        $user->save();
        $this->syncRespondentProfile($user, $originalEmail);

        if (! empty($currentPassword)) {
            $this->syncRespondentPassword($user, $originalEmail);
            Auth::guard('applicant')->logoutOtherDevices($newPassword);
            $request->session()->regenerate();
        }

        return back()->with('success', 'Profile updated.');
    }

    public function updatePassword(Request $request)
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password:applicant'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user = $request->user('applicant');
        $newPassword = $validated['password'];

        $user->forceFill([
            'password' => Hash::make($newPassword),
            'remember_token' => Str::random(60),
        ])->save();
        $this->syncRespondentPassword($user, (string) $user->getRawOriginal('email'));

        Auth::guard('applicant')->logoutOtherDevices($newPassword);
        $request->session()->regenerate();

        return redirect()
            ->to(route($request->routeIs('court.respondent.profile.*') ? 'court.respondent.profile.edit' : 'court.applicant.profile.edit').'#security')
            ->with('security_success', __('court::auth.profile.saved'));
    }

    public function lawyerDocument(Request $request)
    {
        $user = $request->user('applicant');

        abort_if(empty($user->lawyer_document_path), 404, 'Document not found.');

        $disk = Storage::disk('court_private');
        abort_if(! $disk->exists($user->lawyer_document_path), 404, 'Document not found.');

        return $disk->response(
            $user->lawyer_document_path,
            basename($user->lawyer_document_path),
            ['Content-Type' => 'application/pdf']
        );
    }

    private function syncRespondentProfile(object $user, string $originalEmail): void
    {
        $respondent = Respondent::query()
            ->where('email', $originalEmail)
            ->first();

        if ($respondent === null) {
            return;
        }

        $respondent->update([
            'first_name' => $user->first_name,
            'middle_name' => $user->middle_name ?? '',
            'last_name' => $user->last_name,
            'gender' => $user->gender,
            'position' => $user->position ?? '',
            'organization_name' => $user->organization_name ?? '',
            'address' => $user->address ?? '',
            'national_id' => preg_replace('/\D/', '', (string) $user->getRawOriginal('national_id_number')),
            'phone' => $user->phone,
            'email' => $user->email,
        ]);
    }

    private function syncRespondentPassword(object $user, string $originalEmail): void
    {
        $respondent = Respondent::query()
            ->where('email', $originalEmail)
            ->first();

        $respondent?->forceFill(['password' => $user->getRawOriginal('password')])->save();
    }
}

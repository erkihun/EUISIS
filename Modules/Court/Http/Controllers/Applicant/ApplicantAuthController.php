<?php

namespace Modules\Court\Http\Controllers\Applicant;

use Modules\Court\Http\Controllers\Controller;
use Modules\Court\Domain\Models\AboutPage;
use Modules\Court\Domain\Models\Applicant;
use Modules\Court\Application\Support\LoginThrottle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;

class ApplicantAuthController extends Controller
{
    public function showRegister(Request $request)
    {
        // The registration page (React, Court/Applicant/Register).
        return Inertia::render('Court/Applicant/Register')
            ->toResponse($request)
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache');
    }

    public function register(Request $request)
    {
        // Normalize National ID early: keep only digits
        if ($request->filled('national_id_number')) {
            $request->merge([
                'national_id_number' => preg_replace('/\D/', '', $request->input('national_id_number')),
            ]);
        }

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'gender' => ['required', Rule::in(['male', 'female'])],
            'position' => ['required', 'string', 'max:150'],
            'organization_name' => ['required', 'string', 'max:150'],
            'phone' => ['required', 'string', 'max:30', 'unique:court_applicants,phone'],
            'email' => ['required', 'email', 'max:255', 'unique:court_applicants,email'],
            'address' => ['required', 'string', 'max:255'],
            'is_lawyer' => ['required', 'boolean'],
            'lawyer_document' => ['nullable', 'required_if:is_lawyer,1', 'file', 'mimes:pdf', 'max:1024'],

            // normalized (digits-only) National ID
            'national_id_number' => ['required', 'string', 'bail', 'regex:/^\d{16}$/', 'unique:court_applicants,national_id_number'],

            'password' => ['required', 'confirmed', Password::defaults()],
        ], [
            'national_id_number.regex' => __('court::auth.national_id_must_be_16'),
            'lawyer_document.required_if' => __('court::auth.lawyer_document_required'),
        ]);

        $isLawyer = (bool) $data['is_lawyer'];

        $lawyerDocumentPath = null;
        if ($isLawyer && $request->hasFile('lawyer_document')) {
            $lawyerDocumentPath = $request->file('lawyer_document')->store('lawyer_documents', 'court_private');
        }

        unset($data['lawyer_document']);

        // Registration is not email-OTP gated: the account is created straight
        // away. OTP verification is only used for the password-reset flow.
        $applicant = Applicant::create([
            ...$data,
            'is_lawyer' => $isLawyer,
            'lawyer_document_path' => $lawyerDocumentPath,
            'is_active' => true,
        ]);

        // Not mass-assignable — set explicitly.
        $applicant->email_verified_at = now();
        $applicant->save();

        Auth::guard('applicant')->login($applicant);
        $request->session()->regenerate();

        return redirect()->route('court.applicant.dashboard')
            ->with('success', __('court::auth.email_verified_welcome'));
    }

    public function showLogin(Request $request)
    {
        // Always reset acting-as flag on login screen
        session()->forget('acting_as_respondent');
        $loginAs = old('login_as', $request->query('login_as', 'applicant')) === 'respondent' ? 'respondent' : 'applicant';

        $settings = rescue(fn () => SystemSetting::cached(), null, false);
        $bannerPath = ($settings?->show_banner_on_login ?? true) ? ($settings?->banner_path ?? null) : null;
        $aboutPage = rescue(fn () => AboutPage::query()
            ->where('is_published', true)
            ->orderByDesc('updated_at')
            ->first(), null, false);

        // The sign-in page (React, Court/Applicant/Login): the form beside the published About page.
        return Inertia::render('Court/Applicant/Login', [
            'loginAs' => $loginAs,
            'email' => (string) old('email', ''),
            'bannerUrl' => $bannerPath ? asset('storage/'.$bannerPath) : null,
            'about' => $aboutPage ? [
                'title' => (string) $aboutPage->title,
                // Sanitised as the Blade page printed it.
                'body' => (string) \Modules\Court\Application\Support\Purifier::clean($aboutPage->body ?? '', 'cases'),
            ] : null,
        ])
            ->toResponse($request)
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        $existing = Applicant::where('email', $credentials['email'])->first();
        if ($existing && ! $existing->is_active) {
            if ($request->expectsJson()) {
                return response()->json([
                    'errors' => ['email' => ['Your account has been deactivated.']],
                ], 422);
            }

            return back()
                ->withErrors(['email' => 'This account has been deactivated.'])
                ->withInput($request->only('email', 'login_as'));
        }

        $remember = $request->boolean('remember');

        $throttle = LoginThrottle::for('applicant', $credentials['email'], $request->ip());
        if ($throttle->tooManyAttempts()) {
            $seconds = $throttle->availableIn();
            $message = trans('court::auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]);
            if ($request->expectsJson()) {
                return response()->json([
                    'errors' => ['email' => [$message]],
                ], 429);
            }

            return back()
                ->withErrors(['email' => $message])
                ->withInput($request->only('email', 'login_as'));
        }

        $credentials['is_active'] = true;

        $isRespondentMode = $request->input('login_as') === 'respondent';

        // Respondents sign in with an existing account. Accounts are created
        // only through registration (validation, national ID, the registration
        // switch); signing in never creates one.
        if (Auth::guard('applicant')->attempt($credentials, $remember)) {
            $request->session()->regenerate();
            $throttle->clear();

            if ($isRespondentMode) {
                $request->session()->put('acting_as_respondent', true);

                return $this->loginResponse($request, route('court.respondent.dashboard'));
            }

            $request->session()->forget('acting_as_respondent');

            return $this->loginResponse($request, route('court.applicant.dashboard'));
        }

        $throttle->hit();

        if ($request->expectsJson()) {
            return response()->json([
                'errors' => ['email' => [__('court::auth.failed')]],
            ], 422);
        }

        return back()
            ->withErrors(['email' => __('court::auth.failed')])
            ->withInput($request->only('email', 'login_as'));
    }

    private function loginResponse(Request $request, string $target)
    {
        if ($request->expectsJson()) {
            return response()->json([
                'redirect' => $target,
                'message' => __('court::respondent.login_success'),
            ]);
        }

        return redirect($target)->with('success', __('court::respondent.login_success'));
    }

    public function logout(Request $request)
    {
        Auth::guard('applicant')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // With the portal in EUISIS only lawyers sign in here, at /lawyer/login.
        return redirect()->route(\Modules\Court\Application\Support\EuisisParties::portalClosed() ? 'court.lawyer.login' : 'court.applicant.login');
    }
}

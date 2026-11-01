<?php

namespace Modules\Court\Http\Controllers\Respondent;

use Modules\Court\Http\Controllers\Controller;
use Modules\Court\Domain\Models\Applicant;
use Modules\Court\Domain\Models\Respondent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RespondentAuthController extends Controller
{
    /** Respondents sign up and sign in through the applicant portal (as the /respondent/* routes redirect). */
    public function showRegister()
    {
        return redirect()->route('court.applicant.register', ['login_as' => 'respondent']);
    }

    public function register(Request $request)
    {
        $normalizedNationalId = preg_replace('/\D+/', '', (string) $request->input('national_id'));
        $request->merge(['national_id' => $normalizedNationalId ?: null]);

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'gender' => ['nullable', Rule::in(['male', 'female'])],
            'position' => ['required', 'string', 'max:150'],
            'organization_name' => ['required', 'string', 'max:150'],
            'address' => ['required', 'string', 'max:255'],
            'national_id' => ['required', 'digits:16', 'unique:court_respondents,national_id'],
            'phone' => ['required', 'string', 'max:30', 'unique:court_respondents,phone'],
            'email' => ['required', 'email', 'max:255', 'unique:court_respondents,email', 'confirmed'],
            'email_confirmation' => ['required', 'email'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        Respondent::create([
            'first_name' => $data['first_name'],
            'middle_name' => $data['middle_name'],
            'last_name' => $data['last_name'],
            'gender' => $data['gender'] ?? null,
            'position' => $data['position'],
            'organization_name' => $data['organization_name'],
            'address' => $data['address'],
            'national_id' => $data['national_id'],
            'phone' => $data['phone'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
        ]);

        return redirect()->route('court.respondent.register')
            ->with('success', __('court::respondent.registration_success'));
    }

    public function showLogin()
    {
        return redirect()->route('court.applicant.login', ['login_as' => 'respondent']);
    }

    public function login(Request $request)
    {
        // Deprecated: respondent login now handled by applicant login form/guard
        return redirect()->route('court.applicant.login', ['login_as' => 'respondent']);
    }

    public function logout(Request $request)
    {
        // Unified auth now uses the applicant guard; clear both to be safe.
        Auth::guard('respondent')->logout();
        Auth::guard('applicant')->logout();
        $request->session()->forget('acting_as_respondent');
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('court.applicant.login');
    }

    /**
     * Switch from respondent to applicant by logging into the applicant guard.
     */
    public function switchToApplicant(Request $request)
    {
        // Stop acting as respondent but keep the applicant session alive
        $request->session()->forget('acting_as_respondent');

        return redirect()
            ->route('court.applicant.dashboard')
            ->with('success', __('court::app.switch_to_applicant_success'));
    }
}

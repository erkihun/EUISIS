<?php

declare(strict_types=1);

namespace Modules\Court\Http\Controllers\Lawyer;

use Modules\Court\Http\Controllers\Controller;
use Modules\Court\Domain\Models\Applicant;
use Modules\Court\Application\Services\CaseParties;
use Modules\Court\Application\Services\SecureUploadService;
use Modules\Court\Application\Support\LoginThrottle;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The lawyers' sign-in (EUISIS ADR 0013 #13). Employees work on their cases in
 * the EUISIS employee portal; the lawyers who represent them have their own
 * account here, opened by the registry (Lawyers screen) or applied for at
 * /lawyer/register, and verified against their licence. A lawyer signs in on the applicant guard and works on the cases
 * of those they represent in the court portal, which stays open to them alone.
 * Passwords are set and reset by an emailed link (the `applicants` broker).
 */
class LawyerAuthController extends Controller
{
    public function showLogin(Request $request): Response
    {
        return Inertia::render('Court/Lawyer/Login', ['status' => session('status')]);
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        $throttle = LoginThrottle::for('lawyer', $credentials['email'], $request->ip());
        if ($throttle->tooManyAttempts()) {
            $seconds = $throttle->availableIn();

            return back()->withErrors(['email' => trans('court::auth.throttle', ['seconds' => $seconds, 'minutes' => ceil($seconds / 60)])])->onlyInput('email');
        }

        $lawyer = Applicant::query()->where('email', $credentials['email'])->first();
        // One answer for every refusal: an unknown, unverified or inactive lawyer is not told which.
        if ($lawyer === null || ! CaseParties::isVerifiedLawyer($lawyer) || ! Auth::guard('applicant')->attempt([...$credentials, 'is_active' => true, 'is_lawyer' => true], $request->boolean('remember'))) {
            $throttle->hit();

            return back()->withErrors(['email' => __('court::lawyer.failed')])->onlyInput('email');
        }

        $request->session()->regenerate();
        $request->session()->forget('acting_as_respondent');
        $throttle->clear();

        return redirect()->intended(route('court.applicant.cases.index'));
    }

    public function showRegister(): Response
    {
        return Inertia::render('Court/Lawyer/Register');
    }

    /**
     * A lawyer applies to be registered. The account stays closed until the
     * registry verifies the licence on its Lawyers screen; approving it emails
     * the link to choose a password, which also proves the address is theirs.
     */
    public function register(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'gender' => ['required', 'in:male,female'],
            'email' => ['required', 'email', 'max:255', Rule::unique('court_applicants', 'email')],
            'phone' => ['required', 'string', 'max:30', Rule::unique('court_applicants', 'phone')],
            'national_id_number' => ['required', 'string', 'max:32', Rule::unique('court_applicants', 'national_id_number')],
            'address' => ['required', 'string', 'max:255'],
            'license_number' => ['required', 'string', 'max:80', Rule::unique('court_applicants', 'lawyer_license_number')],
            'license_document' => ['required', 'file', 'mimes:pdf', 'max:10240'],
        ]);

        $lawyer = new Applicant([
            'first_name' => trim($data['first_name']), 'middle_name' => trim($data['middle_name']), 'last_name' => trim($data['last_name']),
            'gender' => $data['gender'], 'email' => mb_strtolower(trim($data['email'])), 'phone' => trim($data['phone']),
            'national_id_number' => trim($data['national_id_number']), 'address' => trim($data['address']),
            // A password nobody knows: the lawyer chooses theirs from the link sent on approval.
            'password' => Hash::make(Str::random(48)), 'is_active' => true, 'organization_name' => null, 'position' => null,
        ]);
        $lawyer->forceFill(['is_lawyer' => true, 'lawyer_license_number' => trim($data['license_number']), 'lawyer_applied_at' => now()])->save();
        $lawyer->forceFill(['lawyer_document_path' => app(SecureUploadService::class)->store($request->file('license_document'), 'lawyer-documents', 'court_private', [
            'related_type' => 'applicant', 'related_id' => $lawyer->id,
        ])])->save();

        return redirect()->route('court.lawyer.login')->with('status', __('court::lawyer.apply.received'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('applicant')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('court.lawyer.login');
    }

    public function showForgot(): Response
    {
        return Inertia::render('Court/Lawyer/ForgotPassword', ['status' => session('status')]);
    }

    /** Sends the reset link to a verified lawyer's address; the answer is the same for any address. */
    public function sendResetLink(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);
        $lawyer = Applicant::query()->where('email', $data['email'])->first();
        if ($lawyer !== null && CaseParties::isVerifiedLawyer($lawyer)) {
            Password::broker('applicants')->sendResetLink(['email' => $data['email']]);
        }

        return back()->with('status', __('court::lawyer.reset_link_sent'));
    }

    public function showReset(Request $request, string $token): Response
    {
        return Inertia::render('Court/Lawyer/ResetPassword', ['token' => $token, 'email' => (string) $request->query('email', '')]);
    }

    public function reset(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        $status = Password::broker('applicants')->reset($data, function (Applicant $lawyer, string $password): void {
            $lawyer->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
            event(new PasswordReset($lawyer));
        });

        return $status === Password::PASSWORD_RESET
            ? redirect()->route('court.lawyer.login')->with('status', __('court::lawyer.password_set'))
            : back()->withErrors(['email' => __($status)])->onlyInput('email');
    }
}
